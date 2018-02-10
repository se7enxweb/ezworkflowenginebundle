<?php

namespace Kaliop\eZWorkflowEngineBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

/**
 * Base command class that all migration commands extend from.
 */
abstract class AbstractCommand extends ContainerAwareCommand
{
    /**
     * @var \Kaliop\eZWorkflowEngineBundle\Core\WorkflowService
     */
    private $workflowService;

    /**
     * @return \Kaliop\eZWorkflowEngineBundle\Core\WorkflowService
     */
    public function getWorkflowService()
    {
        if (!$this->workflowService) {
            $this->workflowService = $this->getContainer()->get('ez_workflowengine_bundle.workflow_service');
        }

        return $this->workflowService;
    }
}
