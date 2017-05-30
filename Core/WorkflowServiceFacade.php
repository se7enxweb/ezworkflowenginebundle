<?php

namespace Kaliop\eZWorkflowEngineBundle\Core;

use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\Resource\FileResource;
use Psr\Log\LoggerInterface;
use eZ\Publish\API\Repository\Repository;
use Kaliop\eZMigrationBundle\API\ReferenceBagInterface;
use Kaliop\eZMigrationBundle\Core\MigrationService;
use Kaliop\eZMigrationBundle\API\Value\MigrationDefinition;
use Kaliop\eZMigrationBundle\API\Collection\MigrationDefinitionCollection;
use Kaliop\eZWorkflowEngineBundle\API\Value\WorkflowDefinition;

/**
 * @todo add phpdoc to help IDEs
 */
class WorkflowServiceFacade
{
    protected $innerService;
    protected $cacheDir;
    protected $debugMode;
    protected $referenceResolver;
    protected $logger;
    protected $repository;

    protected static $workflowExecuting = 0;

    public function __construct(MigrationService $innerService, ReferenceBagInterface $referenceResolver, Repository $repository,
        $cacheDir, $debugMode = false, LoggerInterface $logger = null)
    {
        $this->innerService = $innerService;
        $this->referenceResolver = $referenceResolver;
        $this->repository = $repository;
        $this->cacheDir = $cacheDir;
        $this->debugMode = $debugMode;
        $this->logger = $logger;
    }

    /**
     * q: should this method be moved to WorkflowService instead of the Slot ?
     *
     * @param string $signalName must use the same format as we extract from signal class names
     * @param array $parameters must follow what is found in eZ5 signals
     * @throws \Exception
     */
    public function triggerWorkflow($signalName, array $parameters)
    {
        $workflowDefinitions = $this->getValidWorkflowsDefinitionsForSignal($signalName);

        if ($this->logger) $this->logger->debug("Found " . count($workflowDefinitions) . " workflow definitions for signal '$signalName'");

        if (count($workflowDefinitions)) {

            $this->referenceResolver->addReference('workflow:start_time', time(), true);
            $this->referenceResolver->addReference('workflow:original_user', $this->repository->getCurrentUser()->login, true);

            $convertedParameters = array();
            foreach($parameters as $parameter => $value) {
                $convertedParameter = $this->convertSignalMember($signalName, $parameter);
                $this->referenceResolver->addReference('signal:' . $convertedParameter, $value, true);
                $convertedParameters[$convertedParameter] = $value;
            }

            /** @var WorkflowDefinition $workflowDefinition */
            foreach ($workflowDefinitions as $workflowDefinition) {

                if (self::$workflowExecuting > 0 && $workflowDefinition->avoidRecursion) {
                    if ($this->logger) $this->logger->debug("Skipping workflow '{$workflowDefinition->name}' to avoid recursion (workflow already executing)");
                    return;
                }

                $wfd = new WorkflowDefinition(
                    $workflowDefinition->name,
                    $workflowDefinition->path,
                    $workflowDefinition->rawDefinition,
                    $workflowDefinition->status,
                    $workflowDefinition->steps->getArrayCopy(),
                    null,
                    $signalName,
                    $workflowDefinition->runAs,
                    $workflowDefinition->useTransaction,
                    $workflowDefinition->avoidRecursion
                );

                self::$workflowExecuting += 1;
                try {

                    if ($this->logger) $this->logger->debug("Executing workflow '{$workflowDefinition->name}' with parameters: " . preg_replace("/\n+/s", ' ', preg_replace('/^(Array| +|\(|\))/m', '', print_r($convertedParameters, true))));

                    /// @todo allow setting of default lang ?
                    $this->innerService->executeMigration($wfd, $workflowDefinition->useTransaction, null, $workflowDefinition->runAs);
                    self::$workflowExecuting -= 1;
                } catch (\Exception $e) {
                    self::$workflowExecuting -= 1;
                    throw $e;
                }
            }
        }
    }

    /**
     * @param string $signalName
     * @param string $parameter
     * @return string
     */
    protected function convertSignalMember($signalName, $parameter)
    {
        // CamelCase to snake_case using negative look-behind in regexp
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $parameter));
    }

    /**
     * Unlike its parent's similar function, this one only deals with *parsed* definitions.
     * NB: this function, unlike getValidWorkflowsDefinitionsForSignal, does not cache its results, which might lead to
     * some hard-to troubleshoot weirdness...
     * @param string[] $paths
     * @return MigrationDefinitionCollection
     */
    public function getWorkflowsDefinitions($paths = array())
    {
        $defs = array();

        foreach($this->innerService->getMigrationsDefinitions() as $key => $definition) {
            if ($definition->status == MigrationDefinition::STATUS_TO_PARSE) {
                $definition = $this->innerService->parseMigrationDefinition($definition);
            }
            $defs[$key] = $definition;
        }

        return new MigrationDefinitionCollection($defs);
    }

    /**
     * Returns VALID definitions for a given signal.
     * Uses the Sf cache to speed up the process (recipe taken from http://symfony.com/doc/2.7/components/config/caching.html)
     * @param $signalName
     * @param string[] $paths
     * @return MigrationDefinitionCollection
     */
    public function getValidWorkflowsDefinitionsForSignal($signalName, $paths = array())
    {
        $cacheFile = $this->cacheDir . '/' . md5($signalName) . '/' . md5(serialize($paths)) . '.php';

        $cache = new ConfigCache($cacheFile, $this->debugMode);
        if ($cache->isFresh()) {
            return require $cacheFile;
        }

        $defs = array();
        $resources = array();

        foreach($this->getWorkflowsDefinitions($paths) as $key => $definition) {
            /// @todo add safety check that we got back in fact a WorkflowDefinition
            if ($definition->signalName === $signalName && $definition->status == MigrationDefinition::STATUS_PARSED) {
                $defs[$key] = $definition;
                $resources[] = new FileResource($definition->path);
            }
        }

        $collection = new MigrationDefinitionCollection($defs);

        $code = '<?php return '.var_export($collection, true).';';
        $cache->write($code, $resources);

        return $collection;
    }

    public function __call($name, array $arguments)
    {
        $name = str_replace('Workflow', 'Migration', $name);
        return call_user_func_array(array($this->innerService, $name), $arguments);
    }
}
