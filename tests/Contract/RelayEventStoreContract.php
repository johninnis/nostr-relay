<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Contract;

use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\Signature;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\ValueObject\ActingKeys;
use RuntimeException;

final class RelayEventStoreContract
{
    private const string ALICE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const string BOB = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
    private const string CAROL = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';
    private const string AUTHOR = 'd4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4';
    private const string WRAPPER = 'e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5';

    /**
     * @return array<string, array{RelayEventStoreContractCase}>
     */
    public static function cases(): array
    {
        return array_map(static fn (RelayEventStoreContractCase $case): array => [$case], [
            ...self::readCases(),
            ...self::limitCases(),
            ...self::countCases(),
            ...self::orderCases(),
            ...self::unionCases(),
            ...self::deletionCases(),
        ]);
    }

    /**
     * @return array<string, RelayEventStoreContractCase>
     */
    private static function readCases(): array
    {
        $toAlice = self::wrapFor(100, self::ALICE);
        $toBob = self::wrapFor(100, self::BOB);
        $toCarol = self::wrapFor(100, self::CAROL);
        $toBobAndAlice = self::wrapFor(100, self::BOB, self::ALICE);
        $note = self::note(100);
        $noteTaggingBob = self::note(90, self::BOB);

        return [
            'a filter naming no kind reads no gift wrap addressed to another key' => self::reading([[]], [self::ALICE], [])->given($toBob),
            'a filter naming no kind reads the gift wrap addressed to the reader' => self::reading([[]], [self::ALICE], [$toAlice])->given($toAlice),
            'an ids lookup reads the gift wrap addressed to the reader' => self::reading([['ids' => [$toAlice->getId()->toHex()]]], [self::ALICE], [$toAlice])->given($toAlice),
            'an ids lookup reads no gift wrap addressed to another key' => self::reading([['ids' => [$toBob->getId()->toHex()]]], [self::ALICE], [])->given($toBob),
            'a #p filter naming the reader reads its gift wraps' => self::reading([['#p' => [self::ALICE]]], [self::ALICE], [$toAlice])->given($toAlice),
            'a #p filter naming another key reads none of its gift wraps' => self::reading([['#p' => [self::BOB]]], [self::ALICE], [])->given($toBob),
            'a #p filter naming another key still reads other events tagging it' => self::reading([['#p' => [self::BOB]]], [self::ALICE], [$noteTaggingBob])->given($noteTaggingBob),
            'a filter naming the gift-wrap kind and another key reads nothing' => self::reading([['kinds' => [EventKind::GIFT_WRAP], '#p' => [self::BOB]]], [self::ALICE], [])->given($toBob),
            'a reader with several keys reads the gift wraps addressed to each' => self::reading([['kinds' => [EventKind::GIFT_WRAP]]], [self::ALICE, self::CAROL], [$toAlice, self::wrapFor(80, self::CAROL)])
                ->given($toAlice, self::wrapFor(90, self::BOB), self::wrapFor(80, self::CAROL)),
            'a gift wrap addressed to several keys is read by any of them' => self::reading([['kinds' => [EventKind::GIFT_WRAP]]], [self::ALICE], [$toBobAndAlice])->given($toBobAndAlice),
            'a reader with no keys reads no gift wrap' => self::reading([['kinds' => [EventKind::GIFT_WRAP]]], [], [])->given($toAlice, $toCarol),
            'a reader with no keys reads every event of another kind' => self::reading([[]], [], [$note, $noteTaggingBob])->given($note, $noteTaggingBob, $toBob),
            'an ids lookup by a reader with no keys reads no gift wrap' => self::reading([['ids' => [$toAlice->getId()->toHex()]]], [], [])->given($toAlice),
            'a #p filter naming the reader, read by a reader with no keys, reads no gift wrap' => self::reading([['#p' => [self::ALICE]]], [], [])->given($toAlice),
            'an authors filter naming the gift wraps\' signer reads only the wraps addressed to the reader' => self::reading([['authors' => [self::WRAPPER]]], [self::ALICE], [$toAlice])->given($toAlice, $toBob),
            'an authors filter naming the gift wraps\' signer, read by a reader with no keys, reads no gift wrap' => self::reading([['authors' => [self::WRAPPER]]], [], [])->given($toAlice, $toBob),
            'a #p filter naming another key reads a gift wrap only when it also names the reader' => self::reading([['#p' => [self::BOB]]], [self::ALICE], [$toBobAndAlice])->given($toBob, $toBobAndAlice),
            'a filter mixing kinds and naming another key reads a gift wrap only when it also names the reader' => self::reading(
                [['kinds' => [EventKind::TEXT_NOTE, EventKind::GIFT_WRAP], '#p' => [self::BOB]]],
                [self::ALICE],
                [$toBobAndAlice, $noteTaggingBob],
            )->given($toBob, $toBobAndAlice, $noteTaggingBob),
        ];
    }

    /**
     * @return array<string, RelayEventStoreContractCase>
     */
    private static function limitCases(): array
    {
        $toBob = self::wrapFor(400, self::BOB);
        $newerNote = self::note(300);
        $toAlice = self::wrapFor(200, self::ALICE);
        $olderNote = self::note(100);
        $stored = [$toBob, $newerNote, $toAlice, $olderNote];
        $readable = [$newerNote, $toAlice];

        return [
            'a filter naming no kind fills its limit with what the reader may read' => self::reading([['limit' => 2]], [self::ALICE], $readable)->given(...$stored),
            'a filter mixing the gift-wrap kind with another kind fills its limit with what the reader may read' => self::reading(
                [['kinds' => [EventKind::TEXT_NOTE, EventKind::GIFT_WRAP], 'limit' => 2]],
                [self::ALICE],
                $readable,
            )->given(...$stored),
            'an ids lookup fills its limit with what the reader may read' => self::reading(
                [['ids' => array_map(static fn (Event $event): string => $event->getId()->toHex(), $stored), 'limit' => 2]],
                [self::ALICE],
                $readable,
            )->given(...$stored),
            'an authors filter fills its limit with what the reader may read' => self::reading([['authors' => [self::WRAPPER, self::AUTHOR], 'limit' => 2]], [self::ALICE], $readable)->given(...$stored),
        ];
    }

    /**
     * @return array<string, RelayEventStoreContractCase>
     */
    private static function countCases(): array
    {
        $stored = [self::wrapFor(400, self::BOB), self::wrapFor(300, self::ALICE), self::note(200), self::note(100)];

        return [
            'a count counts exactly what the reader may read' => self::counting([[]], [self::ALICE], 3)->given(...$stored),
            'a count of a filter mixing kinds counts exactly what the reader may read' => self::counting([['kinds' => [EventKind::TEXT_NOTE, EventKind::GIFT_WRAP]]], [self::ALICE], 3)->given(...$stored),
            'a count by a reader with no keys counts no gift wrap' => self::counting([[]], [], 2)->given(...$stored),
            'a count of an authors filter naming the gift wraps\' signer counts only the wraps addressed to the reader' => self::counting([['authors' => [self::WRAPPER]]], [self::ALICE], 1)->given(...$stored),
            'a count of a #p filter naming another key counts no gift wrap addressed only to it' => self::counting([['#p' => [self::BOB]]], [self::ALICE], 0)->given(...$stored),
            'a count ignores each filter\'s limit' => self::counting([['kinds' => [EventKind::TEXT_NOTE], 'limit' => 1]], [], 2)->given(...$stored),
        ];
    }

    /**
     * @return array<string, RelayEventStoreContractCase>
     */
    private static function orderCases(): array
    {
        [$lower, $higher] = self::createdAtOnce(100);

        return [
            'a read comes back newest first' => self::reading([['kinds' => [EventKind::TEXT_NOTE]]], [], [self::note(300), self::note(200), self::note(100)])
                ->given(self::note(100), self::note(300), self::note(200)),
            'of two events created at once the lower id comes first' => self::reading([['kinds' => [EventKind::TEXT_NOTE]]], [], [$lower, $higher])->given($higher, $lower),
            'a limit falling between two events created at once keeps the lower id' => self::reading([['kinds' => [EventKind::TEXT_NOTE], 'limit' => 1]], [], [$lower])->given($higher, $lower),
            'each filter is bounded by its own limit' => self::reading(
                [['kinds' => [EventKind::TEXT_NOTE], 'limit' => 1], ['kinds' => [EventKind::GIFT_WRAP], 'limit' => 1]],
                [self::ALICE],
                [self::note(300), self::wrapFor(250, self::ALICE)],
            )->given(self::note(300), self::note(200), self::wrapFor(250, self::ALICE), self::wrapFor(150, self::ALICE)),
        ];
    }

    /**
     * @return array<string, RelayEventStoreContractCase>
     */
    private static function unionCases(): array
    {
        $newerNote = self::note(300);
        $olderNote = self::note(200);
        $overlapping = [['kinds' => [EventKind::TEXT_NOTE]], ['authors' => [self::AUTHOR]]];

        return [
            'a read of filters that overlap returns each event once' => self::reading($overlapping, [], [$newerNote, $olderNote])->given($newerNote, $olderNote, self::wrapFor(100, self::ALICE)),
            'a count of filters that overlap counts each event once' => self::counting($overlapping, [], 2)->given($newerNote, $olderNote, self::wrapFor(100, self::ALICE)),
        ];
    }

    /**
     * @return array<string, RelayEventStoreContractCase>
     */
    private static function deletionCases(): array
    {
        $toAlice = self::wrapFor(100, self::ALICE);
        $toBob = self::wrapFor(100, self::BOB);
        $toBobAndAlice = self::wrapFor(100, self::BOB, self::ALICE);
        $noteTaggingAlice = self::note(100, self::ALICE);
        $deletion = self::deletionBy(self::ALICE);

        return [
            'a recipient deletes a gift wrap addressed to it' => self::deleting($toAlice, self::ALICE, [])->given($toAlice),
            'a key a gift wrap is not addressed to cannot delete it' => self::deleting($toBob, self::ALICE, [$toBob])->given($toBob),
            'a gift wrap\'s signer can still delete it' => self::deleting($toBob, self::WRAPPER, [])->given($toBob),
            'a recipient cannot delete an event of another kind that merely tags it' => self::deleting($noteTaggingAlice, self::ALICE, [$noteTaggingAlice])->given($noteTaggingAlice),
            'the first key a gift wrap is addressed to can delete it' => self::deleting($toBobAndAlice, self::BOB, [])->given($toBobAndAlice),
            'the second key a gift wrap is addressed to can delete it' => self::deleting($toBobAndAlice, self::ALICE, [])->given($toBobAndAlice),
            'an author deletes its own event' => self::deleting($noteTaggingAlice, self::AUTHOR, [])->given($noteTaggingAlice),
            'a deletion is never deleted, even by its author' => self::deleting($deletion, self::ALICE, [$deletion])->given($deletion),
            'a deletion leaves a gift wrap addressed to the deleter that it does not name' => self::deleting($toAlice, self::ALICE, [self::wrapFor(90, self::ALICE)])
                ->given($toAlice, self::wrapFor(90, self::ALICE)),
            'a deletion leaves an event of the deleter\'s that it does not name' => self::deleting($noteTaggingAlice, self::AUTHOR, [self::note(90)])
                ->given($noteTaggingAlice, self::note(90)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $filters
     * @param list<string>               $readerKeys
     * @param list<Event>                $expected
     */
    private static function reading(array $filters, array $readerKeys, array $expected): RelayEventStoreContractCase
    {
        return RelayEventStoreContractCase::reading(self::filters($filters), self::actingAs($readerKeys), new EventCollection($expected));
    }

    /**
     * @param list<array<string, mixed>> $filters
     * @param list<string>               $readerKeys
     */
    private static function counting(array $filters, array $readerKeys, int $expected): RelayEventStoreContractCase
    {
        return RelayEventStoreContractCase::counting(self::filters($filters), self::actingAs($readerKeys), $expected);
    }

    /**
     * @param list<Event> $remaining
     */
    private static function deleting(Event $named, string $deleter, array $remaining): RelayEventStoreContractCase
    {
        return RelayEventStoreContractCase::deleting(new EventIdCollection([$named->getId()]), self::actingAs([$deleter]), new EventCollection($remaining));
    }

    private static function wrapFor(int $createdAt, string ...$recipients): Event
    {
        return self::signed(self::drafted(self::WRAPPER, EventKind::GIFT_WRAP, $createdAt)->withTags(self::tagging(array_values($recipients))));
    }

    private static function note(int $createdAt, string ...$tagged): Event
    {
        return self::signed(self::drafted(self::AUTHOR, EventKind::TEXT_NOTE, $createdAt)->withTags(self::tagging(array_values($tagged))));
    }

    private static function deletionBy(string $author): Event
    {
        return self::signed(self::drafted($author, EventKind::EVENT_DELETION, 100));
    }

    /**
     * @return array{Event, Event}
     */
    private static function createdAtOnce(int $createdAt): array
    {
        $untagged = self::note($createdAt);
        $tagged = self::note($createdAt, self::BOB);

        return strcmp($untagged->getId()->toHex(), $tagged->getId()->toHex()) < 0 ? [$untagged, $tagged] : [$tagged, $untagged];
    }

    private static function drafted(string $author, int $kind, int $createdAt): Rumour
    {
        return Rumour::draft(self::key($author), EventKind::fromInt($kind), EventContent::fromString('contract'), null, Timestamp::fromInt($createdAt));
    }

    private static function signed(Rumour $rumour): Event
    {
        return new Event($rumour, $rumour->getId(), Signature::tryFromHex(str_repeat('f', 128)) ?? throw new RuntimeException('The contract signature did not parse'));
    }

    /**
     * @param list<string> $keys
     */
    private static function tagging(array $keys): TagCollection
    {
        return new TagCollection(array_map(static fn (string $key): Tag => Tag::pubkey(self::key($key)), $keys));
    }

    /**
     * @param list<array<string, mixed>> $filters
     */
    private static function filters(array $filters): FilterCollection
    {
        return new FilterCollection(array_map(
            static fn (array $filter): Filter => Filter::tryFromArray($filter) ?? throw new RuntimeException('A contract filter did not parse'),
            $filters,
        ));
    }

    /**
     * @param list<string> $keys
     */
    private static function actingAs(array $keys): ActingKeys
    {
        return new ActingKeys(new PublicKeyCollection(array_map(self::key(...), $keys)));
    }

    private static function key(string $hex): PublicKey
    {
        return PublicKey::tryFromHex($hex) ?? throw new RuntimeException('A contract key did not parse');
    }
}
