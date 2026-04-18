<?php

namespace Native\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Native\Laravel\Menu\Menu;
use Native\Laravel\MenuBar\MenuBarManager;

/**
 * @method static \Native\Laravel\MenuBar\PendingCreateMenuBar create()
 * @method static void show()
 * @method static void hide()
 * @method static void label(string $label)
 * @method static void tooltip(string $label)
 * @method static void icon(string $icon)
 * @method static void resize(int $width, int $height)
 * @method static void contextMenu(Menu $contextMenu)
 */
class MenuBar extends Facade
{
    protected static function getFacadeAccessor()
    {
        return MenuBarManager::class;
    }
}
