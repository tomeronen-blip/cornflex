/**
 * Moving background admin page: color picker, sortable gallery, media picker.
 */
jQuery(function ($) {
	var $list = $('#cbg_images_sortable');
	var $status = $('#cbg_save_status');

	$('.cbg-color-field').wpColorPicker();
	$list.sortable({ placeholder: 'ui-state-highlight', cursor: 'grabbing' });

	function galleryItem(url) {
		var $li = $('<li class="cbg-sortable-item">')
			.attr('data-url', url)
			.css({ background: '#fafafa', border: '1px solid #dcdcdc', borderRadius: '8px', padding: '8px', cursor: 'grab', textAlign: 'center' });

		$('<img>')
			.attr('src', url)
			.css({ width: '100%', height: '160px', objectFit: 'cover', borderRadius: '6px', display: 'block', marginBottom: '6px' })
			.appendTo($li);

		$('<button type="button" class="button button-link-delete cbg-remove-item-btn">')
			.css('font-size', '12px')
			.text('מחק')
			.appendTo($li);

		return $li;
	}

	$('#cbg_add_images_btn').on('click', function (e) {
		e.preventDefault();

		var frame = wp.media({
			title: 'בחירת תמונות לרקע הנע',
			multiple: true,
			library: { type: 'image' },
			button: { text: 'הוסף לרקע' }
		});

		frame.on('select', function () {
			frame.state().get('selection').each(function (attachment) {
				$list.append(galleryItem(attachment.get('url')));
			});
		});

		frame.open();
	});

	$(document).on('click', '.cbg-remove-item-btn', function () {
		$(this).closest('li').remove();
	});

	$('#cbg_save_images_btn').on('click', function () {
		var $btn = $(this);
		var images = $list.children('li').map(function () {
			return $(this).attr('data-url');
		}).get();

		$btn.prop('disabled', true).text('שומר...');

		$.post(ajaxurl, {
			action: 'cbg_save_bg_gallery',
			nonce: cornflexBoxAdmin.nonce,
			images: images
		}).done(function (res) {
			if (res && res.success) {
				$status.css({ background: '#dcfce7', color: '#15803d', border: '1px solid #86efac' })
					.text('התמונות והסדר נשמרו בהצלחה!').fadeIn().delay(2500).fadeOut();
			} else {
				$status.css({ background: '#fee2e2', color: '#b91c1c', border: '1px solid #fca5a5' })
					.text('שגיאה בשמירה.').fadeIn();
			}
		}).fail(function () {
			$status.css({ background: '#fee2e2', color: '#b91c1c', border: '1px solid #fca5a5' })
				.text('שגיאה בשמירה.').fadeIn();
		}).always(function () {
			$btn.prop('disabled', false).text('שמירת סדר ותמונות');
		});
	});
});
