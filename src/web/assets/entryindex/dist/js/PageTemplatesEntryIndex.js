/* global Craft, Garnish, $ */

/**
 * Adds a "From template" group to Craft's New entry button.
 *
 * Two things about this file are deliberate and worth not "tidying" away.
 *
 * First, it calls the base updateButton() and then adds to what that produced, rather than
 * reimplementing the button. Craft's version handles single-section sites, per-site publishable
 * sections, index versus slideout context, ctrl-click and middle-click. Reproducing that would mean
 * keeping a copy of it in step with Craft forever.
 *
 * Second, everything this file adds is wrapped so a failure cannot take the button with it. This
 * button is how *every* editor creates a page, including those with no template permissions at
 * all. A thrown exception part-way through would leave them with no working button, which is a far
 * worse outcome than templates quietly not being offered.
 */
(function () {
  'use strict';

  if (typeof Craft.PageTemplates === 'undefined') {
    Craft.PageTemplates = {};
  }

  // Injected per element-index page: { sectionHandle: [{id, name}], … }, already filtered to what
  // this user may use here, and already capped (BR-16).
  Craft.PageTemplates.bySection = Craft.PageTemplates.bySection || {};
  Craft.PageTemplates.manageUrl = Craft.PageTemplates.manageUrl || null;
  Craft.PageTemplates.truncated = Craft.PageTemplates.truncated || {};

  Craft.PageTemplates.EntryIndex = Craft.EntryIndex.extend({
    updateButton: function () {
      this.base();

      try {
        this.addTemplateGroup();
      } catch (e) {
        // Never let this break the stock button. See the note at the top of the file.
        console.error('[page-templates] could not add the templates group:', e);
      }
    },

    addTemplateGroup: function () {
      if (!this.$source || !this.$newEntryBtnGroup) {
        return;
      }

      const sectionHandle = this.$source.data('handle');

      if (!sectionHandle) {
        return;
      }

      const templates = Craft.PageTemplates.bySection[sectionHandle] || [];

      // BR: with nothing to offer, the button is left exactly as Craft built it — no group, no
      // disabled item, nothing to explain away.
      if (!templates.length) {
        return;
      }

      const $ul = this.ensureMenuList();

      if (!$ul) {
        return;
      }

      $('<li class="hr"/>').appendTo($ul);

      const $group = $('<li/>')
        .attr('data-page-templates-group', '')
        .appendTo($ul);
      const $groupUl = $('<ul/>').appendTo($group);

      $('<h6/>')
        .text(Craft.t('page-templates', 'From template'))
        .insertBefore($groupUl);

      templates.forEach((template) => {
        const $a = $('<a/>', {
          type: 'button',
          role: 'button',
          text: template.name,
        }).attr('data-page-template-id', template.id);

        $('<li/>').append($a).appendTo($groupUl);

        this.addListener($a, 'activate', () => {
          this.createFromTemplate(template.id, sectionHandle);
        });
      });

      // BR-16. Past the cap the menu stops being usable, so it defers to the full list rather
      // than growing without limit.
      if (Craft.PageTemplates.truncated[sectionHandle] && Craft.PageTemplates.manageUrl) {
        const $more = $('<a/>', {
          href: Craft.PageTemplates.manageUrl,
          text: Craft.t('page-templates', 'All templates…'),
        });

        $('<li/>').append($more).appendTo($groupUl);
      }
    },

    /**
     * Craft only builds a disclosure menu when there is more than one publishable section for the
     * site, so on a single-section site there is nothing to append to and one has to be made.
     */
    ensureMenuList: function () {
      const $existing = this.$newEntryBtnGroup.find('.menu--disclosure ul').first();

      if ($existing.length) {
        return $existing;
      }

      const menuId = 'page-templates-menu-' + Craft.randomString(10);

      const $menuBtn = $('<button/>', {
        type: 'button',
        class: 'btn submit menubtn btngroup-btn-last',
        'aria-controls': menuId,
        'data-disclosure-trigger': '',
        'aria-label': Craft.t('page-templates', 'New entry, choose a template'),
      }).appendTo(this.$newEntryBtnGroup);

      const $menuContainer = $('<div/>', {
        id: menuId,
        class: 'menu menu--disclosure',
      }).appendTo(this.$newEntryBtnGroup);

      const $ul = $('<ul/>').appendTo($menuContainer);

      new Garnish.DisclosureMenu($menuBtn);

      return $ul;
    },

    createFromTemplate: function (templateId, sectionHandle) {
      if (this.$newEntryBtn) {
        this.$newEntryBtn.addClass('loading');
      }

      Craft.sendActionRequest('POST', 'page-templates/create/create', {
        data: {
          templateId: templateId,
          section: sectionHandle,
          siteId: this.siteId,
        },
      })
        .then(({data}) => {
          // Same handoff as Craft's own _createEntry: the new page is already a draft, so this
          // navigates to it rather than opening anything of its own.
          document.location.href = Craft.getUrl(data.cpEditUrl);
        })
        .catch((error) => {
          if (this.$newEntryBtn) {
            this.$newEntryBtn.removeClass('loading');
          }

          const message =
            error?.response?.data?.message ||
            Craft.t('page-templates', 'Couldn’t create a page from that template.');

          Craft.cp.displayError(message);
        });
    },
  });

  // Replaces Craft's registration for entries. Registered after cp.js has defined
  // Craft.EntryIndex, which the asset bundle's CpAsset dependency guarantees.
  Craft.registerElementIndexClass(
    'craft\\elements\\Entry',
    Craft.PageTemplates.EntryIndex
  );
})();
