<?php

namespace Tests\Unit\Enums;

use App\Enums\TaskStatus;
use PHPUnit\Framework\TestCase;

class TaskStatusTest extends TestCase
{
    /** @test */
    public function it_returns_all_enum_values()
    {
        $values = TaskStatus::values();

        $this->assertIsArray($values);
        $this->assertContains('pending', $values);
        $this->assertContains('processing', $values);
        $this->assertContains('completed', $values);
        $this->assertContains('failed', $values);
        $this->assertCount(4, $values);
    }

    /** @test */
    public function it_correctly_identifies_final_states()
    {
        $this->assertTrue(TaskStatus::COMPLETED->isFinal());
        $this->assertTrue(TaskStatus::FAILED->isFinal());
        $this->assertFalse(TaskStatus::PENDING->isFinal());
        $this->assertFalse(TaskStatus::PROCESSING->isFinal());
    }

    /** @test */
    public function it_correctly_identifies_processable_states()
    {
        $this->assertTrue(TaskStatus::PENDING->canProcess());
        $this->assertFalse(TaskStatus::PROCESSING->canProcess());
        $this->assertFalse(TaskStatus::COMPLETED->canProcess());
        $this->assertFalse(TaskStatus::FAILED->canProcess());
    }
}
