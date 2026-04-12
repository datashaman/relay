<?php return array (
  'laravel/pail' => 
  array (
    'providers' => 
    array (
      0 => 'Laravel\\Pail\\PailServiceProvider',
    ),
  ),
  'laravel/tinker' => 
  array (
    'providers' => 
    array (
      0 => 'Laravel\\Tinker\\TinkerServiceProvider',
    ),
  ),
  'nativephp/electron' => 
  array (
    'providers' => 
    array (
      0 => 'Native\\Electron\\ElectronServiceProvider',
    ),
    'aliases' => 
    array (
      'Updater' => 'Native\\Electron\\Facades\\Updater',
    ),
  ),
  'nativephp/laravel' => 
  array (
    'providers' => 
    array (
      0 => 'Native\\Laravel\\NativeServiceProvider',
    ),
    'aliases' => 
    array (
      'ChildProcess' => 'Native\\Laravel\\Facades\\ChildProcess',
      'Clipboard' => 'Native\\Laravel\\Facades\\Clipboard',
      'ContextMenu' => 'Native\\Laravel\\Facades\\ContextMenu',
      'Dock' => 'Native\\Laravel\\Facades\\Dock',
      'GlobalShortcut' => 'Native\\Laravel\\Facades\\GlobalShortcut',
      'Menu' => 'Native\\Laravel\\Facades\\Menu',
      'MenuBar' => 'Native\\Laravel\\Facades\\MenuBar',
      'Notification' => 'Native\\Laravel\\Facades\\Notification',
      'PowerMonitor' => 'Native\\Laravel\\Facades\\PowerMonitor',
      'Process' => 'Native\\Laravel\\Facades\\Process',
      'QueueWorker' => 'Native\\Laravel\\Facades\\QueueWorker',
      'Screen' => 'Native\\Laravel\\Facades\\Screen',
      'Settings' => 'Native\\Laravel\\Facades\\Settings',
      'Shell' => 'Native\\Laravel\\Facades\\Shell',
      'System' => 'Native\\Laravel\\Facades\\System',
      'Window' => 'Native\\Laravel\\Facades\\Window',
    ),
  ),
  'nativephp/mobile' => 
  array (
    'providers' => 
    array (
      0 => 'Native\\Mobile\\MobileServiceProvider',
    ),
  ),
  'nesbot/carbon' => 
  array (
    'providers' => 
    array (
      0 => 'Carbon\\Laravel\\ServiceProvider',
    ),
  ),
  'nunomaduro/collision' => 
  array (
    'providers' => 
    array (
      0 => 'NunoMaduro\\Collision\\Adapters\\Laravel\\CollisionServiceProvider',
    ),
  ),
  'nunomaduro/termwind' => 
  array (
    'providers' => 
    array (
      0 => 'Termwind\\Laravel\\TermwindServiceProvider',
    ),
  ),
);