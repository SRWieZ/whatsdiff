<?php

declare(strict_types=1);

namespace Whatsdiff\Outputs\Tui;

use Chewie\Concerns\CreatesAnAltScreen;
use Chewie\Concerns\RegistersThemes;
use Chewie\Input\KeyPressListener;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

class TerminalUI extends Prompt
{
    use RegistersThemes;
    use CreatesAnAltScreen;
    use MultipleScrolling;

    public array $packages;
    public array $rightPane = [];
    public ?int $selected = null;

    public function __construct(array $packages)
    {
        // Initialize data we are working with
        $this->packages = array_values($packages);
        $this->packages = array_merge($this->packages, $this->packages);

        // Register the theme
        $this->registerTheme(TerminalUIRenderer::class);

        // Set the scroll area
        $this->setScroll('sidebar', 5); // Default one, recalculated later
        $this->initializeMultipleScrolling('sidebar', 0);

        $this->setScroll('content', 5); // Default one, recalculated later
        $this->initializeMultipleScrolling('content', 0);

        $this->createAltScreen();

        // This actions will trigger a re-rendering
        KeyPressListener::for($this)
            ->listenForQuit()
            ->onUp(fn () => $this->previous())
            ->onDown(fn () => $this->next())
            ->on(Key::ENTER, fn () => $this->enter())
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
        return $this->sliceVisible('sidebar', $this->sidebarPackages());
    }

    public function rightPane()
    {
        $array = [];

        if (! $this->isPackageSelected()) {
            return $array;
        }

        $markdown = file_get_contents(__DIR__.'/../../assets/example_changelog.md');
        // $markdown = file_get_contents(__DIR__.'/../../assets/example_text.md');
        $lines = explode("\n", $markdown);

        $uiWidth = self::terminal()->cols();
        $rightPaneWidth = intval(
            $uiWidth
            - ceil($uiWidth / 3)
            - 5
            - 2
        );

        // Break the lines that are too long
        foreach ($lines as $line) {
            if (empty($line)) {
                $array[] = '';

                continue;
            }

            $converter = new MarkdownToConsole();

            $timesToBreak = intval(ceil(mb_strlen($line) / $rightPaneWidth));
            for ($i = 0; $i < $timesToBreak; $i++) {
                $newline = mb_strcut($line, intval($i * $rightPaneWidth), $rightPaneWidth);

                // $newline = $converter->convertInline($newline);

                $array[] = $newline;
            }

        }

        return $array;
    }


    public function rightPaneVisible()
    {
        return $this->sliceVisible('content', $this->rightPane());
    }

    protected function next()
    {
        if ($this->isPackageSelected()) {
            $this->highlightNext('content', count($this->rightPane()), true);

            return;
        }

        $this->highlightNext('sidebar', count($this->packages), true);
    }

    protected function previous()
    {
        if ($this->isPackageSelected()) {
            $this->highlightPrevious('content', count($this->rightPane()), true);

            return;
        }
        $this->highlightPrevious('sidebar', count($this->packages), true);
    }

    public function isPackageSelected()
    {
        return $this->selected !== null;
    }

    public function value(): mixed
    {
        return null;
    }

    private function enter()
    {
        if (! $this->isPackageSelected()) {
            $this->selected = $this->getHighlighted('sidebar');
        }
    }
}
