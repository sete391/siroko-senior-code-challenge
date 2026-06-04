<?php

declare(strict_types=1);

namespace Siroko\Tests\Unit\Shared\Domain\ValueObject;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Siroko\Shared\Domain\Event\DomainEvent;
use Siroko\Shared\Domain\Event\DomainEventRecorderTrait;

/** Describes the public surface returned by makeRecorder(). */
interface TestRecorder
{
    public function recordPublic(DomainEvent $event): void;

    /** @return list<DomainEvent> */
    public function releaseEvents(): array;
}

final class DomainEventRecorderTraitTest extends TestCase
{
    #[Test]
    public function it_starts_with_no_events(): void
    {
        $recorder = $this->makeRecorder();

        self::assertSame([], $recorder->releaseEvents());
    }

    #[Test]
    public function it_records_a_single_event(): void
    {
        $recorder = $this->makeRecorder();
        $event = $this->makeEvent();

        $recorder->recordPublic($event);

        self::assertCount(1, $recorder->releaseEvents());
    }

    #[Test]
    public function it_records_multiple_events_in_order(): void
    {
        $recorder = $this->makeRecorder();
        $first = $this->makeEvent();
        $second = $this->makeEvent();

        $recorder->recordPublic($first);
        $recorder->recordPublic($second);

        $events = $recorder->releaseEvents();

        self::assertSame($first, $events[0]);
        self::assertSame($second, $events[1]);
    }

    #[Test]
    public function it_clears_events_after_release(): void
    {
        $recorder = $this->makeRecorder();
        $recorder->recordPublic($this->makeEvent());

        $recorder->releaseEvents();

        self::assertSame([], $recorder->releaseEvents());
    }

    // ------------------------------------------------------------------ helpers

    private function makeRecorder(): TestRecorder
    {
        return new class implements TestRecorder {
            use DomainEventRecorderTrait;

            public function recordPublic(DomainEvent $event): void
            {
                $this->record($event);
            }
        };
    }

    private function makeEvent(): DomainEvent
    {
        return new class implements DomainEvent {
            public function occurredOn(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };
    }
}
