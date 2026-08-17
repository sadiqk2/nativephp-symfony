<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Dialog\DialogManager;
use Native\Symfony\Desktop\Enums\AlertType;
use PHPUnit\Framework\TestCase;

final class DialogTest extends TestCase
{
    public function testOpenDefaultsToPickingFiles(): void
    {
        $client = new FakeClient();
        $client->willReturn('dialog/open', ['result' => ['/tmp/a.txt']]);

        $paths = (new DialogManager($client))->open()->open();

        self::assertSame(['/tmp/a.txt'], $paths);
        self::assertSame(['openFile'], $client->lastCall()['data']['properties']);
    }

    public function testCancelledOpenYieldsAnEmptyList(): void
    {
        // The runtime answers {result: undefined}, which arrives as a missing key.
        $client = new FakeClient();
        $client->willReturn('dialog/open', []);

        self::assertSame([], (new DialogManager($client))->open()->open());
        self::assertNull((new DialogManager($client))->open()->openOne());
    }

    public function testFiltersAreConvertedToElectronsShape(): void
    {
        $client = new FakeClient();

        (new DialogManager($client))->open()
            ->filters(['Images' => ['png', 'jpg'], 'All' => ['*']])
            ->open();

        self::assertSame([
            ['name' => 'Images', 'extensions' => ['png', 'jpg']],
            ['name' => 'All', 'extensions' => ['*']],
        ], $client->lastCall()['data']['filters']);
    }

    public function testPropertiesAreDeduplicated(): void
    {
        $client = new FakeClient();

        (new DialogManager($client))->open()->files()->files()->multiple()->open();

        self::assertSame(['openFile', 'multiSelections'], $client->lastCall()['data']['properties']);
    }

    public function testSaveReturnsNullWhenCancelled(): void
    {
        $client = new FakeClient();
        $client->willReturn('dialog/save', ['result' => '']);

        self::assertNull((new DialogManager($client))->save()->save());
    }

    public function testSaveReturnsThePath(): void
    {
        $client = new FakeClient();
        $client->willReturn('dialog/save', ['result' => '/tmp/out.pdf']);

        self::assertSame('/tmp/out.pdf', (new DialogManager($client))->save()->defaultPath('/tmp')->save());
    }

    public function testAlertReturnsTheClickedIndexAndOmitsUnsetKeys(): void
    {
        $client = new FakeClient();
        $client->willReturn('alert/message', ['result' => 2]);

        $index = (new DialogManager($client))->alert('Pick', ['A', 'B', 'C'], AlertType::Info);

        self::assertSame(2, $index);
        self::assertSame(
            ['message' => 'Pick', 'buttons' => ['A', 'B', 'C'], 'type' => 'info'],
            $client->lastCall()['data'],
        );
    }

    public function testConfirmBindsCancelToTheSecondButton(): void
    {
        // Without cancelId, Electron maps Escape and the close button to index 0 —
        // which would make dismissing the dialog mean "yes".
        $client = new FakeClient();
        $client->willReturn('alert/message', ['result' => 1]);

        $confirmed = (new DialogManager($client))->confirm('Delete everything?');

        self::assertFalse($confirmed);
        self::assertSame(0, $client->lastCall()['data']['defaultId']);
        self::assertSame(1, $client->lastCall()['data']['cancelId']);
    }

    public function testConfirmIsTrueOnlyForTheFirstButton(): void
    {
        $client = new FakeClient();
        $client->willReturn('alert/message', ['result' => 0]);

        self::assertTrue((new DialogManager($client))->confirm('Sure?'));
    }
}
