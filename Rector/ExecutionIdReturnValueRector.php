<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Rector\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Reads a getter that now returns an `ExecutionId` (#682) through `->toString()`, so the code that
 * read a string keeps reading the same string: a JSON payload keeps `"exec-1"` instead of `{}`,
 * and an array key or a `===` keeps working.
 *
 * A call whose result is already the receiver of another call (`->toString()`, `->equals()`) is
 * left alone: that code reads the object already. Where you want the object, drop the
 * `->toString()` the rule wrote.
 */
final class ExecutionIdReturnValueRector extends AbstractRector
{
    /**
     * Class => its getters that return an `ExecutionId`, and whether they may return null.
     */
    public const GETTERS = [
        'Gplanchat\Durable\WorkflowEnvironment' => ['executionId' => false],
        'Gplanchat\Durable\ExecutionContext' => ['executionId' => false],
        'Gplanchat\Durable\Event\ChildWorkflowScheduled' => ['childExecutionId' => false],
        'Gplanchat\Durable\Event\ChildWorkflowCompleted' => ['childExecutionId' => false],
        'Gplanchat\Durable\Event\ChildWorkflowFailed' => ['childExecutionId' => false],
        'Gplanchat\Durable\Event\WorkflowCancellationRequested' => ['sourceParentExecutionId' => true],
        'Gplanchat\Durable\Event\WorkflowExecutionCancelled' => ['sourceParentExecutionId' => true],
        'Gplanchat\Durable\Event\WorkflowContinuedAsNew' => ['newExecutionId' => true],
    ];

    private const READ = 'durable_execution_id_read';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Read a Durable getter that now returns an ExecutionId through toString()',
            [new CodeSample('$id = $env->executionId();', '$id = $env->executionId()->toString();')],
        );
    }

    public function getNodeTypes(): array
    {
        return [MethodCall::class, NullsafeMethodCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        \assert($node instanceof MethodCall || $node instanceof NullsafeMethodCall);

        // The outer call is visited first: its receiver already reads the object.
        if ($node->var instanceof MethodCall || $node->var instanceof NullsafeMethodCall) {
            $node->var->setAttribute(self::READ, true);
        }

        if (true === $node->getAttribute(self::READ) || $node->isFirstClassCallable()) {
            return null;
        }

        $nullable = $this->nullability($node);
        if (null === $nullable) {
            return null;
        }

        $node->setAttribute(self::READ, true);

        return $nullable ? new NullsafeMethodCall($node, 'toString') : new MethodCall($node, 'toString');
    }

    /**
     * Whether the getter may return null, or null when the call is not one of the getters.
     */
    private function nullability(MethodCall|NullsafeMethodCall $node): ?bool
    {
        $method = $this->getName($node->name);
        foreach (self::GETTERS as $class => $getters) {
            if (null !== $method && isset($getters[$method]) && $this->isObjectType($node->var, new ObjectType($class))) {
                return $getters[$method];
            }
        }

        return null;
    }
}
