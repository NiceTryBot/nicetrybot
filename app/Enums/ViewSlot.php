<?php

namespace App\Enums;

/**
 * The places in the core's views where add-ons put views of their own, through App\Support\ViewSlots.
 */
enum ViewSlot: string
{
    /**
     * The <head> of every page drawn with partials/head (signed in, signing in and the public pages;
     * never the gym's), before the core's own CSS and JS: styles and scripts.
     */
    case Head = 'head';

    /**
     * The header of the public pages (the welcome page and x-public-layout), before Log in or Open
     * your workspace: small ghost buttons.
     */
    case PublicNav = 'public-nav';

    /**
     * The welcome page, after its introduction: sections.
     */
    case WelcomeSections = 'welcome-sections';

    /**
     * The login page, under the form: other ways in, as buttons or links.
     */
    case LoginActions = 'login-actions';

    /**
     * The top of the signed-in pages, above the page itself: notices across the page.
     */
    case AppBanner = 'app-banner';

    /**
     * The top right of the app's pages, before the cog: small buttons.
     */
    case TopbarActions = 'topbar-actions';

    /**
     * The end of the signed-in pages' <body>, after the page: scripts, data blocks and modals.
     */
    case BodyEnd = 'body-end';

    /**
     * The Domains page's heading, before Add domain, for those who can add domains: buttons. Its views
     * get the $team.
     */
    case DomainsActions = 'domains-actions';

    /**
     * The Domains page, before the team's domains of their own: sections, like the team's shared
     * origins. Its views get the $team.
     */
    case DomainsSections = 'domains-sections';

    /**
     * The side column of a domain's page, before Remove domain: panels. Its views get the $domain.
     */
    case DomainPanels = 'domain-panels';

    /**
     * The Rules page's list column, after its template gallery, for those who can write rules: links
     * and panels. Its views get the $team.
     */
    case RulesGallery = 'rules-gallery';

    /**
     * The Rules page's editor, under "Send its clients to the gym", while a saved rule is open: panels
     * that do something with its clients. Its views get the $team and the $rule.
     */
    case RulePanels = 'rule-panels';

    /**
     * The Activity page's list, in place of the request list, when its Map view is picked: a view of
     * the filtered traffic. Its views get the $team, the $period, the filtered $domainId, the parsed
     * search $where and the $dimension picked.
     */
    case ActivityMap = 'activity-map';

    /**
     * The right-click menu on a client, on the Activity page after the core's items and on the Traffic
     * Map page on its own: menu items. Its views get the $team, and draw in the menu's Alpine scope, with
     * open, the client's kind (ip or ja4), value and, for an IP, its asn if known.
     * The menu is hidden until opened, so what an item opens goes in ActivityModals.
     */
    case ActivityClientMenu = 'activity-client-menu';

    /**
     * The end of the Activity and Traffic Map pages, after their own modals: modals, and whatever else
     * their menu items open. Its views get the $team.
     */
    case ActivityModals = 'activity-modals';

    /**
     * The admin White List page's "Good bots" tab, which only appears while something fills it: a panel
     * of ready-made allow lists (the Nice Bots add-on). Admin-only, so the views can embed a Livewire
     * component behind a @can('administer') check.
     */
    case WhiteListGoodBots = 'white-list-good-bots';
}
