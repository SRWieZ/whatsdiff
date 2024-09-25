<?php

namespace App\Renderers;

use App\Outputs\TerminalUI;
use Chewie\Concerns\Aligns;
use Chewie\Concerns\DrawsHotkeys;
use Laravel\Prompts\Themes\Default\Renderer;

class TerminalUIRenderer extends Renderer
{
    use Aligns;
    use DrawsHotkeys;

    public function __invoke(TerminalUI $prompt): static
    {
        $width = $prompt->terminal()->cols();
        $height = $prompt->terminal()->lines();

        $this->newLine();
        $this->centerHorizontally("What's Diff?", $width)->each($this->line(...));
        $this->line(str_repeat($this->dim('─'), $width));

        // Sidebar
        $sidebar = collect($prompt->packages)
            ->map(fn($package) => ' '.$package['name'].' ')
            ->map(fn($name, $i) => match ($i) {
                $prompt->selected => $this->bgGreen($name),
                $prompt->cursor => $this->bgMagenta($name),
                default => $name,
            })
            ->each($this->line(...));

        $this->pinToBottom($height, function () use ($prompt, $width) {
            $this->newLine();

            $this->hotkey('↑', 'Up', active: ($prompt->cursor > 0));
            $this->hotkey('↓', 'Down', active: ($prompt->cursor < count($prompt->packages) - 1));
            $this->hotkey('Enter', 'Select');

            $this->hotkeyQuit();
            $this->line(str_repeat($this->dim('─'), $width));
            $this->centerHorizontally($this->hotkeys(), $width)->each($this->line(...));
        });

        return $this;
    }
}
