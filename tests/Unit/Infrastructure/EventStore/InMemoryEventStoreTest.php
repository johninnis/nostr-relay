<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Infrastructure\EventStore;

use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventCoordinate;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Innis\Nostr\Relay\Infrastructure\EventStore\InMemoryEventStore;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InMemoryEventStoreTest extends TestCase
{
    public function testAnEventIsStoredOnceAndThenSeenAsADuplicate(): void
    {
        $store = new InMemoryEventStore();
        $event = $this->note('hello', 100);

        $this->assertSame(EventStoreOutcome::Stored, $store->store($event));
        $this->assertSame(EventStoreOutcome::Duplicate, $store->store($event));
    }

    public function testANewerReplaceableEventReplacesTheOlder(): void
    {
        $store = new InMemoryEventStore();
        $store->store($this->profile('first', 100));
        $newer = $this->profile('second', 200);

        $this->assertSame(EventStoreOutcome::Stored, $store->store($newer));

        $found = $store->findByFilters($this->filters(['kinds' => [EventKind::METADATA]]));

        $this->assertSame(['second'], self::contents($found));
    }

    public function testAnOlderReplaceableEventIsSuperseded(): void
    {
        $store = new InMemoryEventStore();
        $store->store($this->profile('current', 200));

        $this->assertSame(EventStoreOutcome::Superseded, $store->store($this->profile('stale', 100)));
    }

    public function testAddressableEventsWithDifferentIdentifiersCoexist(): void
    {
        $store = new InMemoryEventStore();
        $store->store($this->article('one', 'first-slug', 100));
        $store->store($this->article('two', 'second-slug', 100));

        $this->assertCount(2, $store->findByFilters($this->filters(['kinds' => [EventKind::LONGFORM_CONTENT]])));
    }

    public function testAnAddressableEventReplacesTheSameIdentifier(): void
    {
        $store = new InMemoryEventStore();
        $store->store($this->article('draft', 'a-slug', 100));
        $store->store($this->article('revised', 'a-slug', 200));

        $found = $store->findByFilters($this->filters(['kinds' => [EventKind::LONGFORM_CONTENT]]));

        $this->assertCount(1, $found);
        $this->assertSame('revised', self::contents($found)[0] ?? null);
    }

    public function testAnOrdinaryKindIsNeverReplaced(): void
    {
        $store = new InMemoryEventStore();
        $store->store($this->note('first', 100));
        $store->store($this->note('second', 200));

        $this->assertCount(2, $store->findByFilters($this->filters(['kinds' => [EventKind::TEXT_NOTE]])));
    }

    public function testTheFiltersOwnLimitIsApplied(): void
    {
        $store = $this->storeWithNotes(5);

        $this->assertCount(2, $store->findByFilters($this->filters(['kinds' => [EventKind::TEXT_NOTE], 'limit' => 2])));
    }

    public function testAFilterNamingNoLimitIsNotBoundedByTheStore(): void
    {
        $store = $this->storeWithNotes(5);

        $this->assertCount(5, $store->findByFilters($this->filters(['kinds' => [EventKind::TEXT_NOTE]])));
    }

    public function testEachFilterIsBoundedByItsOwnLimit(): void
    {
        $store = $this->storeWithNotes(5);

        $filters = new FilterCollection([
            Filter::tryFromArray(['kinds' => [EventKind::TEXT_NOTE], 'limit' => 2]) ?? throw new RuntimeException('Invalid filter'),
            Filter::tryFromArray(['authors' => [KeyMother::ALICE_PUBLIC_KEY_HEX], 'limit' => 1]) ?? throw new RuntimeException('Invalid filter'),
        ]);

        $this->assertCount(2, $store->findByFilters($filters));
    }

    public function testResultsComeBackNewestFirst(): void
    {
        $store = $this->storeWithNotes(3);

        $found = $store->findByFilters($this->filters(['kinds' => [EventKind::TEXT_NOTE]]));

        $this->assertSame(['note 3', 'note 2', 'note 1'], self::contents($found));
    }

    public function testACountIgnoresTheFiltersPageSize(): void
    {
        $store = $this->storeWithNotes(12);

        $counted = $store->countByFilters($this->filters(['kinds' => [EventKind::TEXT_NOTE], 'limit' => 3]));

        $this->assertSame(12, $counted->toInt());
        $this->assertFalse($counted->isApproximate());
    }

    public function testACountOfTwoOverlappingFiltersCountsAnEventOnce(): void
    {
        $store = $this->storeWithNotes(3);

        $filters = new FilterCollection([
            Filter::tryFromArray(['kinds' => [EventKind::TEXT_NOTE]]) ?? throw new RuntimeException('Invalid filter'),
            Filter::tryFromArray(['authors' => [KeyMother::ALICE_PUBLIC_KEY_HEX]]) ?? throw new RuntimeException('Invalid filter'),
        ]);

        $this->assertSame(3, $store->countByFilters($filters)->toInt());
    }

    public function testAnEventMatchingTwoFiltersIsReturnedOnce(): void
    {
        $store = new InMemoryEventStore();
        $store->store($this->note('only', 100));

        $filters = new FilterCollection([
            Filter::tryFromArray(['kinds' => [EventKind::TEXT_NOTE]]) ?? throw new RuntimeException('Invalid filter'),
            Filter::tryFromArray(['authors' => [KeyMother::ALICE_PUBLIC_KEY_HEX]]) ?? throw new RuntimeException('Invalid filter'),
        ]);

        $this->assertCount(1, $store->findByFilters($filters));
    }

    public function testWhatIsReadBackIsTheEventsOwnEncodingNotTheBytesItArrivedAs(): void
    {
        $note = $this->note('hello', 100);
        $arrived = Event::tryFromJson(json_encode([...$note->toArray(), 'evil' => 'unsigned'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))
            ?? throw new RuntimeException('Fixture did not parse');
        $store = new InMemoryEventStore();
        $store->store($arrived);

        $found = $store->findByFilters($this->filters(['kinds' => [EventKind::TEXT_NOTE]]));

        $this->assertSame([$note->toJson()], array_map(static fn (StoredEvent $stored): string => $stored->getEncoded()->toJson(), $found->toArray()));
    }

    public function testAReplaceableCoordinateDeletesTheAuthorsVersionUpToTheRequest(): void
    {
        $store = new InMemoryEventStore();
        $store->store($this->profile('profile', 100));

        $this->assertSame(1, $this->deleteByCoordinate($store, EventKind::METADATA, '', 150));
        $this->assertCount(0, $store->findByFilters($this->filters(['kinds' => [EventKind::METADATA]])));
    }

    public function testAReplaceableCoordinateDeletesAVersionCreatedAtTheRequestsOwnTimestamp(): void
    {
        $store = new InMemoryEventStore();
        $store->store($this->profile('profile', 150));

        $this->assertSame(1, $this->deleteByCoordinate($store, EventKind::METADATA, '', 150));
    }

    public function testAReplaceableCoordinateLeavesAVersionNewerThanTheRequest(): void
    {
        $store = new InMemoryEventStore();
        $store->store($this->profile('profile', 200));

        $this->assertSame(0, $this->deleteByCoordinate($store, EventKind::METADATA, '', 150));
        $this->assertSame(['profile'], self::contents($store->findByFilters($this->filters(['kinds' => [EventKind::METADATA]]))));
    }

    public function testAReplaceableCoordinateLeavesAnotherAuthorsVersion(): void
    {
        $store = new InMemoryEventStore();
        $store->store($this->event(EventKind::METADATA, 'bob', 100, new TagCollection([]), KeyMother::bobPublicKey()));

        $this->assertSame(0, $this->deleteByCoordinate($store, EventKind::METADATA, '', 150));
        $this->assertSame(['bob'], self::contents($store->findByFilters($this->filters(['kinds' => [EventKind::METADATA]]))));
    }

    public function testAnAddressableCoordinateDeletesOnlyItsOwnIdentifierUpToTheRequest(): void
    {
        $store = new InMemoryEventStore();
        $store->store($this->article('target', 'a-slug', 100));
        $store->store($this->article('other', 'another-slug', 100));

        $this->assertSame(1, $this->deleteByCoordinate($store, EventKind::LONGFORM_CONTENT, 'a-slug', 150));
        $this->assertSame(['other'], self::contents($store->findByFilters($this->filters(['kinds' => [EventKind::LONGFORM_CONTENT]]))));
    }

    public function testAnAddressableCoordinateLeavesAVersionNewerThanTheRequest(): void
    {
        $store = new InMemoryEventStore();
        $store->store($this->article('target', 'a-slug', 200));

        $this->assertSame(0, $this->deleteByCoordinate($store, EventKind::LONGFORM_CONTENT, 'a-slug', 150));
    }

    public function testAnAddressableCoordinateWithAnEmptyIdentifierDeletesTheEmptyIdentifiersVersion(): void
    {
        $store = new InMemoryEventStore();
        $store->store($this->article('empty', '', 100));
        $store->store($this->article('named', 'a-slug', 100));

        $this->assertSame(1, $this->deleteByCoordinate($store, EventKind::LONGFORM_CONTENT, '', 150));
        $this->assertSame(['named'], self::contents($store->findByFilters($this->filters(['kinds' => [EventKind::LONGFORM_CONTENT]]))));
    }

    public function testAnAddressableCoordinateWithAnEmptyIdentifierDeletesAVersionWithNoDTagUpToTheRequest(): void
    {
        $store = new InMemoryEventStore();
        $store->store($this->articleWithoutDTag('untagged', 100));
        $store->store($this->article('named', 'a-slug', 100));

        $this->assertSame(1, $this->deleteByCoordinate($store, EventKind::LONGFORM_CONTENT, '', 150));
        $this->assertSame(['named'], self::contents($store->findByFilters($this->filters(['kinds' => [EventKind::LONGFORM_CONTENT]]))));
    }

    public function testAnAddressableCoordinateWithAnEmptyIdentifierLeavesAVersionWithNoDTagNewerThanTheRequest(): void
    {
        $store = new InMemoryEventStore();
        $store->store($this->articleWithoutDTag('untagged', 200));

        $this->assertSame(0, $this->deleteByCoordinate($store, EventKind::LONGFORM_CONTENT, '', 150));
    }

    public function testAnAddressableEventWithAnEmptyDTagReplacesOneWithNoDTag(): void
    {
        $store = new InMemoryEventStore();
        $store->store($this->articleWithoutDTag('untagged', 100));
        $store->store($this->article('empty', '', 200));

        $this->assertSame(['empty'], self::contents($store->findByFilters($this->filters(['kinds' => [EventKind::LONGFORM_CONTENT]]))));
    }

    /**
     * @return list<string>
     */
    private static function contents(StoredEventCollection $found): array
    {
        return array_map(
            static fn (StoredEvent $stored): string => (string) (Event::tryFromJson($stored->getEncoded()->toJson())?->getContent() ?? ''),
            $found->toArray(),
        );
    }

    private function deleteByCoordinate(InMemoryEventStore $store, int $kind, string $identifier, int $requestedAt): int
    {
        $coordinate = EventCoordinate::tryFrom(EventKind::fromInt($kind), KeyMother::alicePublicKey(), $identifier)
            ?? throw new RuntimeException('Invalid coordinate');

        return $store->deleteByCoordinates(new EventCoordinateCollection([$coordinate]), KeyMother::alicePublicKey(), Timestamp::fromInt($requestedAt));
    }

    private function storeWithNotes(int $count): InMemoryEventStore
    {
        $store = new InMemoryEventStore();

        for ($i = 1; $i <= $count; ++$i) {
            $store->store($this->note('note '.$i, 100 + $i));
        }

        return $store;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function filters(array $raw): FilterCollection
    {
        return new FilterCollection([Filter::tryFromArray($raw) ?? throw new RuntimeException('Invalid filter')]);
    }

    private function note(string $content, int $createdAt): Event
    {
        return $this->event(EventKind::TEXT_NOTE, $content, $createdAt, new TagCollection([]));
    }

    private function profile(string $content, int $createdAt): Event
    {
        return $this->event(EventKind::METADATA, $content, $createdAt, new TagCollection([]));
    }

    private function article(string $content, string $identifier, int $createdAt): Event
    {
        return $this->event(
            EventKind::LONGFORM_CONTENT,
            $content,
            $createdAt,
            new TagCollection([Tag::tryFromArray(['d', $identifier])]),
        );
    }

    private function articleWithoutDTag(string $content, int $createdAt): Event
    {
        $rumour = Rumour::tryFromFields([
            'pubkey' => KeyMother::alicePublicKey()->toHex(),
            'created_at' => $createdAt,
            'kind' => EventKind::LONGFORM_CONTENT,
            'tags' => [],
            'content' => $content,
        ]);
        $this->assertInstanceOf(Rumour::class, $rumour);

        return EventMother::fromRumour($rumour);
    }

    private function event(int $kind, string $content, int $createdAt, TagCollection $tags, ?PublicKey $author = null): Event
    {
        return EventMother::fromRumour(Rumour::draft(
            $author ?? KeyMother::alicePublicKey(),
            EventKind::fromInt($kind),
            EventContent::fromString($content),
            $tags,
            Timestamp::fromInt($createdAt),
        ));
    }
}
