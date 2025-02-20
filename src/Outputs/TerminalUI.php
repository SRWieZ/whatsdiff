<?php

namespace App\Outputs;

use App\Renderers\TerminalUIRenderer;
use Chewie\Concerns\CreatesAnAltScreen;
use Chewie\Concerns\RegistersThemes;
use Chewie\Input\KeyPressListener;
use Laravel\Prompts\Concerns\Scrolling;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

class TerminalUI extends Prompt
{
    use RegistersThemes;
    use CreatesAnAltScreen;
    use Scrolling;

    public array $packages;
    public ?int $selected = null;

    public function __construct(array $packages)
    {
        // Initialize data we are working with
        $this->packages = array_values($packages);
        $this->packages = array_merge($this->packages, $this->packages);

        // Register the theme
        $this->registerTheme(TerminalUIRenderer::class);

        // Set the scroll area
        $this->scroll = 10;
        $this->initializeScrolling(0);

        // $this->createAltScreen();

        // This actions will trigger a re-rendering
        KeyPressListener::for($this)
            ->listenForQuit()
            ->onUp(fn () => $this->previous())
            ->onDown(fn () => $this->next())
            ->on(Key::ENTER, fn () => $this->selected = $this->highlighted)
            ->on(Key::ESCAPE, fn () => $this->selected = null)
            // ->on([Key::HOME, Key::CTRL_A], fn() => $this->highlighted !== null ? $this->highlight(0) : null)
            // ->on([Key::END, Key::CTRL_E],
            //     fn() => $this->highlighted !== null ? $this->highlight(count($this->packages) - 1) : null)
            ->listen();
    }

    public function sidebarPackages()
    {
        return collect($this->packages)
            ->toArray();
    }

    public function sidebarVisiblePackages(): array
    {
        return array_slice($this->sidebarPackages(), $this->firstVisible, $this->scroll, preserve_keys: true);
    }

    protected function next()
    {
        if ($this->isNavigatingSidebar()) {
            $this->highlightNext(count($this->packages), true);
            // $this->cursor = min($this->cursor + 1, count($this->packages) - 1);
        }

    }

    protected function previous()
    {
        if ($this->isNavigatingSidebar()) {
            $this->highlightPrevious(count($this->packages), true);
            // $this->cursor = max($this->cursor - 1, 0);
        }

    }

    protected function isNavigatingSidebar()
    {
        return is_null($this->selected);
    }

    public function value(): mixed
    {
        return null;
    }
}
