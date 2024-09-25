<?php

namespace App\Outputs;

use App\Renderers\TerminalUIRenderer;
use Chewie\Concerns\CreatesAnAltScreen;
use Chewie\Concerns\RegistersThemes;
use Chewie\Input\KeyPressListener;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

class TerminalUI extends Prompt
{
    use RegistersThemes;
    use CreatesAnAltScreen;

    public array $packages;
    public int $cursor = 0;
    public int $selected = 0;

    public function __construct(array $packages)
    {
        $this->packages = array_values($packages);

        $this->registerTheme(TerminalUIRenderer::class);

        // $this->createAltScreen();

        KeyPressListener::for($this)
            ->listenForQuit()
            ->onUp(fn () => $this->previous())
            ->onDown(fn () => $this->next())
            ->on(Key::ENTER,fn () => $this->selected = $this->cursor)
            ->listen();
    }

    protected function next()
    {
        $this->cursor = min($this->cursor + 1, count($this->packages) - 1);
    }

    protected function previous()
    {
        $this->cursor = max($this->cursor - 1, 0);
    }

    public function value(): mixed
    {
        return null;
    }
}
