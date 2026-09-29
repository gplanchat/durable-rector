<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Rector\Rector;

use Gplanchat\Durable\ExecutionId;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name\FullyQualified;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Wraps a string execution id in `ExecutionId::fromString()` where a port now takes the value
 * object (#638).
 *
 * It only touches an argument it can prove is a string, on a receiver it can prove is one of the
 * ports. A named argument, an unpacked one, or one whose type is unknown is left as it is: the type
 * checker names what is left. It does not migrate the code that consumes a return value that became
 * an `ExecutionId`, nor a class that implements a port — UPGRADE.md describes both by hand.
 */
final class ExecutionIdArgumentRector extends AbstractRector
{
    /**
     * Port => method => positions of the execution id arguments.
     */
    public const PORTS = [
        'Gplanchat\Durable\Store\EventStoreInterface' => [
            'readStream' => [0], 'readStreamWithRecordedAt' => [0], 'countEventsInStream' => [0],
        ],
        'Gplanchat\Durable\Store\WorkflowMetadataStore' => [
            'save' => [0], 'markCompleted' => [0], 'get' => [0], 'hasActiveWorkflowMetadata' => [0], 'delete' => [0],
        ],
        'Gplanchat\Durable\Port\WorkflowRunCatalogInterface' => ['findRun' => [0]],
        'Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface' => [
            'link' => [0, 1], 'getParentExecutionId' => [0], 'getChildExecutionIdsForParent' => [0], 'unlink' => [0],
        ],
        'Gplanchat\Durable\Port\WorkflowResumeDispatcher' => [
            'dispatchResume' => [0], 'dispatchResumeAwaiting' => [0], 'dispatchNewWorkflowRun' => [0],
        ],
        'Gplanchat\Durable\Port\WorkflowHistorySourceInterface' => [
            'hasChildExecutionId' => [0], 'hasChildExecutionCompletedSuccessfully' => [0],
        ],
        'Gplanchat\Durable\Port\WorkflowBackendInterface' => ['start' => [0]],
        'Gplanchat\Bridge\Temporal\WorkflowClientInterface' => [
            'startAsync' => [2], 'startSync' => [2], 'workflowId' => [0],
        ],
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Wrap a string execution id passed to a Durable port in ExecutionId::fromString()',
            [new CodeSample(
                '$eventStore->readStream($executionId);',
                '$eventStore->readStream(\Gplanchat\Durable\ExecutionId::fromString($executionId));',
            )],
        );
    }

    public function getNodeTypes(): array
    {
        return [MethodCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        \assert($node instanceof MethodCall);

        if ($node->isFirstClassCallable()) {
            return null;
        }

        $method = $this->getName($node->name);
        if (null === $method) {
            return null;
        }

        $changed = false;
        foreach (self::PORTS as $port => $methods) {
            if (!isset($methods[$method]) || !$this->isObjectType($node->var, new ObjectType($port))) {
                continue;
            }

            foreach ($methods[$method] as $position) {
                $arg = $node->args[$position] ?? null;
                if (!$arg instanceof Arg || null !== $arg->name || $arg->unpack || !$this->getType($arg->value)->isString()->yes()) {
                    continue;
                }

                $arg->value = new StaticCall(new FullyQualified(ExecutionId::class), 'fromString', [new Arg($arg->value)]);
                $changed = true;
            }

            break;
        }

        return $changed ? $node : null;
    }
}
