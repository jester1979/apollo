<?php

declare(strict_types=1);

namespace Apollo\Chapter;

/**
 * The interlude (tussenstuk) between two chapters: a short animation with a title. It covers the part of
 * the mission that the port does not fly, such as the trans-lunar coast or the rendezvous. The server
 * owns the titles and the mission order, so the page never repeats either.
 */
final class Interlude
{
    /** The title of the interlude that follows $chapter, or null after the last chapter. */
    public static function titleAfter(ChapterId $chapter): ?string
    {
        return match ($chapter) {
            ChapterId::Launch => 'Trans-lunar injection and coast',
            ChapterId::LunarOrbitInsertion => 'Undocking and descent orbit',
            ChapterId::Landing => 'On the surface',
            ChapterId::Ascent => 'Rendezvous and docking',
            ChapterId::TransEarthInjection => 'Coast back to Earth',
            ChapterId::Entry => null,
        };
    }

    /**
     * What the page needs to offer "Next chapter": the next chapter and the interlude before it. It exists
     * only after a successful chapter that has a successor.
     *
     * @return array{chapter: string, interlude: string}|null
     */
    public static function nextAfter(ChapterId $chapter, ?Outcome $outcome): ?array
    {
        $next = $chapter->next();
        $title = self::titleAfter($chapter);
        if ($outcome !== Outcome::Success || $next === null || $title === null) {
            return null;
        }

        return ['chapter' => $next->value, 'interlude' => $title];
    }
}
