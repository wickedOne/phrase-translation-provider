<?php

declare(strict_types=1);

/*
 * This file is part of the Phrase Symfony Translation Provider.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Translation\Bridge\Phrase\Tests\Event;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Bridge\Phrase\Event\PhraseReadEvent;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\TranslatorBag;

/**
 * @author wicliff <wicliff.wolda@gmail.com>
 */
class PhraseEventTest extends TestCase
{
    public function testSetAndGetBag(): void
    {
        $event = new PhraseReadEvent(new TranslatorBag());

        $bag = $event->getBag();
        $catalogue = new MessageCatalogue('de');
        $bag->addCatalogue($catalogue);

        $this->assertSame($catalogue, $event->getBag()->getCatalogue('de'));
    }
}
