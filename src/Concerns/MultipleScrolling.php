<?php

namespace Whatsdiff\Concerns;

use Laravel\Prompts\Themes\Contracts\Scrolling as ScrollingRenderer;

trait MultipleScrolling
{
    /**
     * The number of items to display before scrolling.
     */
    protected array $scroll = [];

    /**
     * The index of the highlighted option.
     */
    protected array $highlighted = [];

    /**
     * The index of the first visible option.
     */
    protected array $firstVisible = [];

    /**
     * Get the scroll value for a given key.
     */
    public function getScroll(string $key): ?int
    {
        return $this->scroll[$key] ?? null;
    }

    public function setScroll(string $key, int $scroll): void
    {
        $this->scroll[$key] = $scroll;
    }

    /**
     * Get the highlighted value for a given key.
     */
    public function getHighlighted(string $key): ?int
    {
        return $this->highlighted[$key] ?? null;
    }

    /**
     * Get the first visible index for a given key.
     */
    public function getFirstVisible(string $key): ?int
    {
        return $this->firstVisible[$key] ?? null;
    }

    /**
     * Initialize scrolling for the given key.
     *
     * @param  string  $key
     * @param  int|null  $highlighted
     */
    protected function initializeMultipleScrolling(string $key, ?int $highlighted = null): void
    {
        $this->highlighted[$key] = $highlighted;
        $this->firstVisible[$key] = 0;

        $this->reduceScrollingToFitTerminal($key);
    }

    /**
     * Reduce the scroll property to fit the terminal height for the given key.
     *
     * Assumes that $this->scroll[$key] has already been set.
     *
     * @param  string  $key
     */
    protected function reduceScrollingToFitTerminal(string $key): void
    {
        $reservedLines = ($renderer = $this->getRenderer()) instanceof ScrollingRenderer ? $renderer->reservedLines() : 0;

        $this->scroll[$key] = max(1, min($this->scroll[$key], $this->terminal()->lines() - $reservedLines));
    }

    /**
     * Highlight the given index for the specified scroll bar key.
     *
     * @param  string  $key
     * @param  int|null  $index
     */
    protected function highlight(string $key, ?int $index): void
    {
        $this->highlighted[$key] = $index;

        if ($this->highlighted[$key] === null) {
            return;
        }

        if ($this->highlighted[$key] < $this->firstVisible[$key]) {
            $this->firstVisible[$key] = $this->highlighted[$key];
        } elseif ($this->highlighted[$key] > $this->firstVisible[$key] + $this->scroll[$key] - 1) {
            $this->firstVisible[$key] = $this->highlighted[$key] - $this->scroll[$key] + 1;
        }
    }

    /**
     * Highlight the previous entry for the given key, or wrap around to the last entry.
     *
     * @param  string  $key
     * @param  int  $total
     * @param  bool  $allowNull
     */
    protected function highlightPrevious(string $key, int $total, bool $allowNull = false): void
    {
        if ($total === 0) {
            return;
        }

        if ($this->highlighted[$key] === null) {
            $this->highlight($key, $total - 1);
        } elseif ($this->highlighted[$key] === 0) {
            $this->highlight($key, $allowNull ? null : ($total - 1));
        } else {
            $this->highlight($key, $this->highlighted[$key] - 1);
        }
    }

    /**
     * Highlight the next entry for the given key, or wrap around to the first entry.
     *
     * @param  string  $key
     * @param  int  $total
     * @param  bool  $allowNull
     */
    protected function highlightNext(string $key, int $total, bool $allowNull = false): void
    {
        if ($total === 0) {
            return;
        }

        if ($this->highlighted[$key] === $total - 1) {
            $this->highlight($key, $allowNull ? null : 0);
        } else {
            $this->highlight($key, ($this->highlighted[$key] ?? -1) + 1);
        }
    }

    /**
     * Center the highlighted option for the given key.
     *
     * @param  string  $key
     * @param  int  $total
     */
    protected function scrollToHighlighted(string $key, int $total): void
    {
        if ($this->highlighted[$key] < $this->scroll[$key]) {
            return;
        }

        $remaining = $total - $this->highlighted[$key] - 1;
        $halfScroll = (int) floor($this->scroll[$key] / 2);
        $endOffset = max(0, $halfScroll - $remaining);

        if ($this->scroll[$key] % 2 === 0) {
            $endOffset--;
        }

        $this->firstVisible[$key] = $this->highlighted[$key] - $halfScroll - $endOffset;
    }

    public function sliceVisible(string $key, array $items): array
    {
        return array_slice(
            array: $items,
            offset: $this->firstVisible[$key],
            length: $this->scroll[$key],
            preserve_keys: true
        );
    }
}
