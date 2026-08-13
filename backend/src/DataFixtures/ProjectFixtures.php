<?php

namespace App\DataFixtures;

use App\Entity\Project;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class ProjectFixtures extends Fixture
{
    public const PROJECT_COUNT = 5;

    public function load(ObjectManager $manager): void
    {
        for ($i = 1; $i <= self::PROJECT_COUNT; $i++) {
            $project = new Project();
            $project->setName('Project number '.$i);
            $manager->persist($project);

            // Named so TaskFixtures can hang its tasks off a real project.
            $this->addReference(self::reference($i), $project);
        }

        $manager->flush();
    }

    public static function reference(int $index): string
    {
        return 'project-'.$index;
    }
}
