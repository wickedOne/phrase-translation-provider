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
use Symfony\Component\Translation\TranslatorBag;

/**
 * @author wicliff <wicliff.wolda@gmail.com>
 */
class PhraseEventTest extends TestCase
{
    public function testSetAndGetBag(): void
    {
        $bagOne = new TranslatorBag();
        $bagTwo = new TranslatorBag();

        $event = new PhraseReadEvent($bagOne);

        $this->assertSame($bagOne, $event->getBag());

        $event->setBag($bagTwo);

        $this->assertSame($bagTwo, $event->getBag());
    }
}
