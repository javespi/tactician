<?php
namespace League\Tactician\Tests\Setup;

use League\Tactician\StandardCommandBus;
use League\Tactician\Setup\QuickStart;
use League\Tactician\Tests\Fixtures\Command\AddTaskCommand;
use League\Tactician\Tests\Fixtures\Command\CompleteTaskCommand;
use Mockery;

class QuickStartTest extends \PHPUnit_Framework_TestCase
{
    public function testReturnsACommandBus()
    {
        $commandBus = QuickStart::create([]);
        $this->assertInstanceOf(StandardCommandBus::class, $commandBus);
    }

    public function testCommandToHandlerMapIsProperlyConfigured()
    {
        $map = [
            AddTaskCommand::class => Mockery::mock(ConcreteMethodsHandler::class),
            CompleteTaskCommand::class => Mockery::mock(ConcreteMethodsHandler::class),
        ];

        $map[AddTaskCommand::class]->shouldReceive('handle')->once();
        $map[CompleteTaskCommand::class]->shouldReceive('handle')->never();

        $commandBus = QuickStart::create($map);
        $commandBus->execute(new AddTaskCommand());
    }
}