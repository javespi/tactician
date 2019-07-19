<?php
declare(strict_types=1);

namespace League\Tactician\PHPStan;

use League\Tactician\CommandBus;
use League\Tactician\Handler\HandlerNameInflector\HandlerNameInflector;
use League\Tactician\Handler\MethodNameInflector\MethodNameInflector;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Broker\Broker;
use PHPStan\Broker\ClassNotFoundException;
use PHPStan\Reflection\BrokerAwareExtension;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MissingMethodFromReflectionException;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Rules\Rule;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

final class TacticianRuleSet implements Rule, DynamicMethodReturnTypeExtension, BrokerAwareExtension
{
    /**
     * @var HandlerNameInflector
     */
    private $handlerNameInflector;

    /**
     * @var MethodNameInflector
     */
    private $methodNameInflector;

    /**
     * @var Broker
     */
    private $broker;

    public function __construct(
        HandlerNameInflector $handlerNameInflector,
        MethodNameInflector $methodNameInflector
    ) {
        $this->handlerNameInflector = $handlerNameInflector;
        $this->methodNameInflector = $methodNameInflector;
    }

    public function setBroker(Broker $broker): void
    {
        $this->broker = $broker;
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $methodCall, Scope $scope): array
    {
        if (!$methodCall instanceof MethodCall) {
            return [];
        }

        $type = $scope->getType($methodCall->var);

        if (!$type instanceof ObjectType || $type->getClassName() !== CommandBus::class) {
            return [];
        }

        // Wrong number of arguments passed to handle? Delegate to other PHPStan rules
        if (count($methodCall->args) !== 1) {
            return []; //
        }

        $commandType = $scope->getType($methodCall->args[0]->value);

        // did user violate the object typehint by passing something else?
        // exit to delegate to other PHPStan rules
        if (!$commandType instanceof ObjectType) {
            return [];
        }

        $handlerClassName = $this->handlerNameInflector->getHandlerClassName($commandType->getClassName());

        try {
            $handlerClass = $this->broker->getClass($handlerClassName);
        } catch (ClassNotFoundException $e) {
            return [
                "Tactician tried to route the command {$commandType->getClassName()} but could not find the matching handler {$handlerClassName}."
            ];
        }

        $methodName = $this->methodNameInflector->inflect($commandType->getClassName(), $handlerClass->getName());

        if (!$handlerClass->hasMethod($methodName)) {
            return [
                "Tactician tried to route the command {$commandType->getClassName()} to {$handlerClass->getName()}::{$methodName} but while the class could be loaded, the method '{$methodName}' could not be found on the class."
            ];
        }

        /** @var \PHPStan\Reflection\ParameterReflection[] $parameters */
        $parameters = ParametersAcceptorSelector::selectFromArgs(
            $scope,
            $methodCall->args,
            $handlerClass->getMethod($methodName, $scope)->getVariants()
        )->getParameters();

        if (count($parameters) === 0) {
            return [
                "Tactician tried to route the command {$commandType->getClassName()} to {$handlerClass->getName()}::{$methodName} but the method '{$methodName}' does not accept any parameters."
            ];
        }

        if (count($parameters) > 1) {
            return [
                "Tactician tried to route the command {$commandType->getClassName()} to {$handlerClass->getName()}::{$methodName} but the method '{$methodName}' accepts too many parameters."
            ];
        }

        if ($parameters[0]->getType()->accepts($commandType, true)->no()) {
            return [
                "Tactician tried to route the command {$commandType->getClassName()} to {$handlerClass->getName()}::{$methodName} but the method '{$methodName}' has a typehint that does not allow this command."
            ];
        }

        return [];
    }

    public function getClass(): string
    {
        return CommandBus::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'handle';
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope
    ): Type {
        $commandType = $scope->getType($methodCall->args[0]->value);

        if (!$commandType instanceof ObjectType) {
            return new MixedType();
        }

        try {
            $handlerClass = $this->broker->getClass(
                $this->handlerNameInflector->getHandlerClassName($commandType->getClassName())
            );
        } catch (ClassNotFoundException $e) {
            return new MixedType();
        }

        $methodName = $this->methodNameInflector->inflect($commandType->getClassName(), $handlerClass->getName());

        try {
            $method = $handlerClass->getMethod($methodName, $scope)->getVariants();
        } catch (MissingMethodFromReflectionException $e) {
            return new MixedType();
        }

        return ParametersAcceptorSelector::selectFromArgs($scope, $methodCall->args, $method)->getReturnType();
    }
}
