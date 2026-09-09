/* global Craft, Garnish, $ */

/**
 * The save-as-template dialogue, opened from a page's own action menu.
 *
 * Built on Garnish.Modal and Craft's own field markup so it looks and behaves like the rest of the
 * control panel, and inherits its keyboard handling rather than reimplementing it.
 */
(function () {
  'use strict';

  if (typeof Craft.PageTemplates === 'undefined') {
    Craft.PageTemplates = {};
  }

  Craft.PageTemplates.SaveDialogue = Garnish.Modal.extend({
    entryId: null,
    siteId: null,
    $nameInput: null,
    $descriptionInput: null,
    $includeContentInput: null,
    $saveBtn: null,
    $spinner: null,
    $errors: null,

    init: function (entryId, siteId) {
      this.entryId = entryId;
      this.siteId = siteId;

      const $container = $('<div class="modal fitted"/>').appendTo(Garnish.$bod);
      const $body = $('<div class="body"/>').appendTo($container);

      $('<h2/>')
        .text(Craft.t('page-templates', 'Save as a page template'))
        .appendTo($body);

      $('<p class="light"/>')
        .text(
          Craft.t(
            'page-templates',
            'This page is not changed. The template starts out usable only in the area this page belongs to.'
          )
        )
        .appendTo($body);

      this.$errors = $('<ul class="errors" hidden/>').appendTo($body);

      this.$nameInput = this.textField($body, Craft.t('page-templates', 'Name'), true);
      this.$descriptionInput = this.textField($body, Craft.t('page-templates', 'Description'), false);

      const $contentField = $('<div class="field"/>').appendTo($body);
      const $contentInput = $('<div class="input"/>').appendTo($contentField);
      const checkboxId = 'pt-include-content-' + Math.floor(Math.random() * 1000000);

      this.$includeContentInput = $('<input type="checkbox" class="checkbox"/>')
        .attr('id', checkboxId)
        .prop('checked', true)
        .appendTo($contentInput);

      $('<label/>')
        .attr('for', checkboxId)
        .text(Craft.t('page-templates', 'Include this page’s content'))
        .appendTo($contentInput);

      $('<div class="instructions"/>')
        .append(
          $('<p/>').text(
            Craft.t(
              'page-templates',
              'Leave this off for a skeleton: the blocks arrive in the same order with every field empty.'
            )
          )
        )
        .appendTo($contentField);

      const $footer = $('<div class="footer"/>').appendTo($container);
      const $buttons = $('<div class="buttons right"/>').appendTo($footer);

      $('<button/>', {type: 'button', class: 'btn', text: Craft.t('app', 'Cancel')})
        .appendTo($buttons)
        .on('click', () => this.hide());

      this.$saveBtn = $('<button/>', {
        type: 'submit',
        class: 'btn submit',
        text: Craft.t('page-templates', 'Save template'),
      }).appendTo($buttons);

      this.$spinner = $('<div class="spinner hidden"/>').appendTo($buttons);

      this.base($container, {onShow: () => this.$nameInput.trigger('focus')});

      this.addListener(this.$saveBtn, 'click', 'save');
      // Enter submits, which is what anyone typing a name will expect.
      this.addListener(this.$nameInput, 'keydown', (event) => {
        if (event.keyCode === Garnish.RETURN_KEY) {
          event.preventDefault();
          this.save();
        }
      });
    },

    textField: function ($body, label, required) {
      const $field = $('<div class="field"/>').appendTo($body);
      const $heading = $('<div class="heading"/>').appendTo($field);
      const $label = $('<label/>').text(label).appendTo($heading);

      if (required) {
        $label.addClass('required');
      }

      return $('<input type="text" class="text fullwidth"/>').appendTo(
        $('<div class="input"/>').appendTo($field)
      );
    },

    save: function () {
      if (this.$saveBtn.hasClass('disabled')) {
        return;
      }

      this.$errors.attr('hidden', true).empty();
      this.$saveBtn.addClass('disabled');
      this.$spinner.removeClass('hidden');

      Craft.sendActionRequest('POST', 'page-templates/templates/save-from-entry', {
        data: {
          entryId: this.entryId,
          siteId: this.siteId,
          name: this.$nameInput.val(),
          description: this.$descriptionInput.val(),
          includeContent: this.$includeContentInput.prop('checked') ? 1 : 0,
        },
      })
        .then((response) => {
          Craft.cp.displayNotice(response.data.message);
          this.hide();
        })
        .catch((error) => {
          const data = error && error.response ? error.response.data : null;
          const messages = [];

          if (data && data.errors) {
            Object.keys(data.errors).forEach((key) => {
              data.errors[key].forEach((message) => messages.push(message));
            });
          } else if (data && data.message) {
            messages.push(data.message);
          } else {
            messages.push(Craft.t('page-templates', 'Couldn’t save the template.'));
          }

          // Kept in the dialogue rather than flashed and dismissed, so what the editor typed
          // survives the error and they can correct it.
          messages.forEach((message) => $('<li/>').text(message).appendTo(this.$errors));
          this.$errors.removeAttr('hidden');
        })
        .finally(() => {
          this.$saveBtn.removeClass('disabled');
          this.$spinner.addClass('hidden');
        });
    },
  });
})();
