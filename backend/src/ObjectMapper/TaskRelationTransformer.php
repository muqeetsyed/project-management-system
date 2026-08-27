<?php

namespace App\ObjectMapper;

use App\ApiResource\ProjectResource;
use App\ApiResource\TaskResource;
use App\Entity\Project;
use App\Entity\Task;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\ObjectMapper\TransformCallableInterface;

class TaskRelationTransformer implements TransformCallableInterface {

    public function __invoke(mixed $value, object $source, ?object $target): mixed {

        $taskResources = [];

        if($value instanceof Collection) {
            foreach($value as $task) {
                if ($task instanceof Task) {
                    $dto = new TaskResource();

                    $dto->id = $task->getId();
                    $dto->title = $task->getTitle();
                    $dto->description = $task->getDescription();
                    $dto->completed = $task->isCompleted();
                    $dto->dueDate = $task->getDueDate();
                    $dto->completedAt = $task->getCompletedAt();

                    if (($project = $task->getProject() )instanceof Project) {
                        $pResource = new ProjectResource();
                        $pResource->id = $project->getId();
                        $pResource->name = $project->getName();
                        $pResource->description = $project->getDescription();
                        $pResource->status = $project->getStatus();
                        $pResource->createdAt = $project->getCreatedAt();
                        $dto->project = $pResource;
                    }

                    $taskResources[] = $dto;
                }
            }

            return $taskResources;
        }

        return [];
    }
}
