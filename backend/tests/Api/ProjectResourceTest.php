<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\ProjectResource;
use App\Entity\Project;
use App\Enum\ProjectStatus;
use Doctrine\ORM\EntityManagerInterface;

final class ProjectResourceTest extends ApiTestCase
{
    // Opts in to the API Platform 5 behaviour explicitly; leaving this null
    // triggers a deprecation, and phpunit.dist.xml has failOnDeprecation="true".
    protected static ?bool $alwaysBootKernel = true;

    private Client $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        // createClient() boots the kernel, so it must run before getContainer().
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Each test builds the data it asserts on. Relying on ProjectFixtures would
     * couple these assertions to a file that changes for unrelated reasons.
     */
    private function createProject(string $name = 'Apollo'): Project
    {
        $project = new Project();
        $project->setName($name);
        $project->setStatus(ProjectStatus::Active);

        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    public function testGetCollectionReturnsProjects(): void
    {
        $this->createProject('Apollo');
        $this->createProject('Gemini');

        $this->client->request('GET', '/api/projects');

        $this->assertResponseStatusCodeSame(200);
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');

        // A subset assertion — adding a field to the resource must not break this.
        $this->assertJsonContains([
            '@context' => '/api/contexts/Project',
            'totalItems' => 2,
        ]);

        $this->assertMatchesResourceCollectionJsonSchema(ProjectResource::class);
    }

    public function testGetSingleProjectExposesExpectedFields(): void
    {
        $project = $this->createProject('Apollo');

        $response = $this->client->request('GET', '/api/projects/'.$project->getId());

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'name' => 'Apollo',
            'status' => 'active',
        ]);
        $this->assertMatchesResourceItemJsonSchema(ProjectResource::class);

        // Guards the DTO boundary: the entity owns a tasks collection, the
        // resource deliberately does not expose it.
        $data = $response->toArray();
        $this->assertArrayHasKey('createdAt', $data);
        $this->assertArrayNotHasKey('tasks', $data);
    }

    public function testGetUnknownProjectReturns404(): void
    {
        $this->client->request('GET', '/api/projects/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testPostCreatesProject(): void
    {
        $this->client->request('POST', '/api/projects', [
            'json' => [
                'name' => 'Voyager',
                'description' => 'Deep space programme',
                'status' => 'active',
            ],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains(['name' => 'Voyager']);
        $this->assertMatchesResourceItemJsonSchema(ProjectResource::class);

        // Clearing the EM forces a real database read rather than a hit on the
        // identity map, so this verifies persistence and not just the echo.
        $this->em->clear();
        $saved = $this->em->getRepository(Project::class)->findOneBy(['name' => 'Voyager']);

        $this->assertNotNull($saved);
        $this->assertSame(ProjectStatus::Active, $saved->getStatus());
        $this->assertSame('Deep space programme', $saved->getDescription());
    }

    public function testPostWithBlankNameReturnsValidationError(): void
    {
        $response = $this->client->request('POST', '/api/projects', [
            'json' => ['name' => '', 'description' => 'No name given'],
        ]);

        // 422, not 400 — the payload parsed fine, it failed a business rule.
        $this->assertResponseStatusCodeSame(422);

        // toArray(false) so a 4xx status does not throw.
        $violations = $response->toArray(false)['violations'];

        $this->assertSame('name', $violations[0]['propertyPath']);
        $this->assertSame('This value should not be blank.', $violations[0]['message']);
    }

    public function testPostWithOverlongNameReturnsValidationError(): void
    {
        $this->client->request('POST', '/api/projects', [
            'json' => ['name' => str_repeat('a', 256)],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertJsonContains(['violations' => [['propertyPath' => 'name']]]);
    }

    public function testPatchUpdatesProject(): void
    {
        $project = $this->createProject('Apollo');

        $this->client->request('PATCH', '/api/projects/'.$project->getId(), [
            'json' => ['status' => 'archived'],
            // API Platform requires this exact type for PATCH; omitting it is a 415.
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'status' => 'archived',
            'name' => 'Apollo',
        ]);
    }
}
