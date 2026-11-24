<?php

declare(strict_types=1);

namespace KimaiPlugin\OrbisRolesBundle\EventListener;

use App\Event\ConfigureMainMenuEvent;
use App\Utils\MenuItemModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the Orbis Roles admin screen to Kimai's main menu (under Administration). Kimai itself
 * gates a menu item by whether its route's permission is granted, so no extra flag is needed.
 */
final class ConfigureMenuListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [ConfigureMainMenuEvent::class => ['onMenuConfigure', 100]];
    }

    public function onMenuConfigure(ConfigureMainMenuEvent $event): void
    {
        $admin = $event->getAdminMenu();
        $admin->addChild(new MenuItemModel('project_roles', 'Project roles', 'orbis_roles_admin_list', [], 'users'));
        $admin->addChild(new MenuItemModel('project_roles_members', 'Role assignments', 'orbis_roles_members', [], 'user-check'));
    }
}