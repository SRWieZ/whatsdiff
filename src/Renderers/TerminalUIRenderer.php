<?php

namespace App\Renderers;

use App\Outputs\TerminalUI;
use Chewie\Concerns\Aligns;
use Chewie\Concerns\DrawsHotkeys;
use Illuminate\Support\Collection;
use Laravel\Prompts\Themes\Contracts\Scrolling;
use Laravel\Prompts\Themes\Default\Concerns\DrawsScrollbars;
use Laravel\Prompts\Themes\Default\Concerns\InteractsWithStrings;
use Laravel\Prompts\Themes\Default\Renderer;

class TerminalUIRenderer extends Renderer implements Scrolling
{
    use Aligns;
    use DrawsHotkeys;
    use DrawsScrollbars;
    use InteractsWithStrings;

    protected int $minWidth;
    protected int $uiWidth;
    protected int $uiHeight;

    protected TerminalUI $terminalUI;
    protected int $contentHeight;

    public function __invoke(TerminalUI $prompt): static
    {
        $this->terminalUI = $prompt;
        $this->uiWidth = $prompt->terminal()->cols();
        $this->uiHeight = $prompt->terminal()->lines();

        $header = $this->layoutHeader();
        $footer = $this->layoutFooter();

        // Calculate the content height
        $this->contentHeight = $this->uiHeight - $header->count() - $footer->count();
        $this->terminalUI->scroll = $this->contentHeight;

        // Let's make the SideBar 1/3 of the terminal width
        $this->minWidth = intval(ceil($this->uiWidth / 3));

        // Render the sidebar
        $sidebar = $this->layoutSidebar();

        // Render all the layout
        $header->each($this->line(...));
        $sidebar->each($this->line(...));
        $this->renderBottom($this->uiHeight, $footer);

        return $this;
    }


    protected function renderBottom(int $height, $bottom)
    {
        // Count line breaks in current string
        $lineBreaks = substr_count($this->output, PHP_EOL);

        $padding = $height - $lineBreaks - count($bottom);

        if ($padding > 0) {
            $this->newLine($padding);
        }

        $bottom->each($this->line(...));
    }

    public function reservedLines(): int
    {
        return 0;
    }

    private function layoutHeader(): Collection
    {
        return collect([
            '',
            $this->dim(str_repeat('─', $this->uiWidth)),
            ... $this->centerHorizontally("What's Diff?", $this->uiWidth)->toArray(),
            $this->dim(str_repeat('─', $this->uiWidth)),
        ]);
    }

    private function layoutFooter(): Collection
    {

        // Hotkeys
        $this->hotkey('↑', 'Up');
        $this->hotkey('↓', 'Down');
        $this->hotkey('Enter', 'Select');
        $this->hotkey('Esc', 'Back', active: ($this->terminalUI->selected > -1));
        $this->hotkeyQuit();

        $footer = [
            // Bottom border
            $this->dim(str_repeat('─', $this->uiWidth)),

            // Debug infos
            $this->spaceBetween($this->uiWidth, ...[
                $this->terminalUI->selected !== null ? 'Selected: '.$this->terminalUI->sidebarPackages()[$this->terminalUI->highlighted]['name'] : 'No package selected',
                $this->terminalUI->selected !== null ? $this->terminalUI->sidebarPackages()[$this->terminalUI->highlighted]['from'] ?? '' : '',
                $this->terminalUI->selected !== null ? $this->terminalUI->sidebarPackages()[$this->terminalUI->highlighted]['to'] ?? '' : '',
                // $this->terminalUI->highlighted??'',
            ]),

            // Another border
            $this->dim(str_repeat('─', $this->uiWidth)),

            // Show all hotkeys centered
        ];
        $footer = array_merge($footer, $this->centerHorizontally($this->hotkeys(), $this->uiWidth)->toArray());

        return collect($footer);
    }

    public function layoutSidebar(): Collection
    {

        return collect($this->scrollbar(
            visible: array_map(function ($package, $key) {

                $name = $package['name'];
                $type = rand(0, 1) ? 'PHP' : 'JS';

                // // Add Icon before the name
                // $icon = str_pad($type, 5, ' ', STR_PAD_BOTH);
                // $icon = match ($type) {
                //     'PHP' => $this->bgBlue($this->white($icon)),
                //     'JS' => $this->bgYellow($this->black($icon)),
                // };
                //
                // $name = $icon.' '.$name;
                $label = $name;


                $index = array_search($key, array_keys($this->terminalUI->sidebarPackages()));


                // Cursor represented by an arrow
                // $name = $this->terminalUI->highlighted === $index ? '➤'.$name : ' '.$name;
                $label = '  '.$label;

                // Truncate the name to fit in the sidebar and his scrollbar
                $innerWidth = $this->minWidth - 1;
                $label = $this->truncate($label, $innerWidth);
                $label = mb_str_pad($label, $innerWidth + 1, ' ', STR_PAD_RIGHT);


                // If nothing is selected and the cursor is on it, highlight it
                if ($this->terminalUI->selected === null && $this->terminalUI->highlighted === $index) {

                    return $this->bgWhite($this->black('›')).' '.$this->white(mb_strcut($label, 2));
                }

                // If it's selected, highlight it with a white background
                if ($this->terminalUI->selected === $index && $this->terminalUI->highlighted === $index) {
                    return $this->reset($this->bgWhite($this->black('› '.mb_strcut($label, 2))));
                }

                return $this->gray($label);

            }, $visible = $this->terminalUI->sidebarVisiblePackages(), array_keys($visible)),
            firstVisible: $this->terminalUI->firstVisible,
            height: $this->terminalUI->scroll,
            total: count($this->terminalUI->sidebarPackages()),
            // width: min($this->longest($this->terminalUI->sidebarPackages(), padding: 4), $this->uiWidth - 6)
            width: $this->minWidth,
        ));
    }
}
