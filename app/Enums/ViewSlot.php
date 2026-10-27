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
}
