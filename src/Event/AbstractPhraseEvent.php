<?php

declare(strict_types=1);

/*
 * This file is part of the Phrase Symfony Translation Provider.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Translation\Bridge\Phrase\Event;

use Symfony\Component\Translation\TranslatorBag;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * @author wicliff <wicliff.wolda@gmail.com>
 */
abstract class AbstractPhraseEvent extends Event
{
    public function __construct(
        private readonly TranslatorBag $bag,
    ) {
    }

    public function getBag(): TranslatorBag
    {
        return $this->bag;
    }
}
