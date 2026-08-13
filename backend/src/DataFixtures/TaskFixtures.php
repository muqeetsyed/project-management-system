<?php

namespace App\DataFixtures;

use App\Entity\Project;
use App\Entity\Task;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class TaskFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * Title, description, due date as an offset in days from today (null for no
     * due date) and whether the task is already done.
     *
     * The data is fixed rather than random so every load produces the same
     * database, which keeps manual checks and tests reproducible.
     *
     * @var list<array{title: string, description: ?string, dueInDays: ?int, completed: bool}>
     */
    private const TASK_TEMPLATES = [
        ['title' => 'Write the project brief', 'description' => 'Agree the scope and deliverables with the client.', 'dueInDays' => -21, 'completed' => true],
        ['title' => 'Set up the repository', 'description' => 'Create the repo, the CI pipeline and branch protection.', 'dueInDays' => -14, 'completed' => true],
        ['title' => 'Build the API endpoints', 'description' => 'Expose projects and tasks over the REST API.', 'dueInDays' => -2, 'completed' => false],
        ['title' => 'Review the test coverage', 'description' => null, 'dueInDays' => 7, 'completed' => false],
        ['title' => 'Plan the release', 'description' => 'Pick a release window and write the changelog.', 'dueInDays' => null, 'completed' => false],
    ];

    public function load(ObjectManager $manager): void
    {
        $today = new \DateTimeImmutable('today');

        for ($projectIndex = 1; $projectIndex <= ProjectFixtures::PROJECT_COUNT; $projectIndex++) {
            $project = $this->getReference(ProjectFixtures::reference($projectIndex), Project::class);

            // Each project gets a different number of tasks, taken from a
            // rotating window of the templates, so the seeded data covers an
            // almost empty project as well as a busy one.
            $taskCount = 1 + ($projectIndex % \count(self::TASK_TEMPLATES));

            for ($offset = 0; $offset < $taskCount; $offset++) {
                $template = self::TASK_TEMPLATES[($projectIndex + $offset) % \count(self::TASK_TEMPLATES)];

                $task = new Task();
                $task->setTitle($template['title']);
                $task->setDescription($template['description']);
                $task->setDueDate(null === $template['dueInDays'] ? null : $today->modify($template['dueInDays'].' days'));
                // setCompleted() owns the completedAt stamp, so it is not set here.
                $task->setCompleted($template['completed']);
                $task->setProject($project);

                $manager->persist($task);
            }
        }

        $manager->flush();
    }

    /**
     * Tasks require a project (the join column is NOT NULL), so the projects
     * must be loaded first.
     */
    public function getDependencies(): array
    {
        return [ProjectFixtures::class];
    }
}
