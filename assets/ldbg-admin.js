(function($){
	function tabTo(name){
		$('.ldbg-tab').removeClass('active');
		$('.ldbg-tab[data-tab="'+name+'"]').addClass('active');
		$('.ldbg-panel').hide();
		$('.ldbg-panel[data-panel="'+name+'"]').show();
	}
	$('.ldbg-tab').on('click', function(){ tabTo($(this).data('tab')); });

	function post(action, data){
		return $.post(LDBG.ajax, Object.assign({ action, nonce: LDBG.nonce }, data||{}));
	}

	$('#ldbg-process-now').on('click', function(e){
		e.preventDefault();
		post('ldbg_process_now').done(()=>location.reload());
	});

	$('#ldbg-clear-queue').on('click', function(e){
		e.preventDefault();
		if (!confirm('Clear all queued items?')) return;
		post('ldbg_clear_queue').done(()=>location.reload());
	});

	$('.ldbg-delete-item').on('click', function(e){
		e.preventDefault();
		const index = $(this).closest('tr').data('index');
		post('ldbg_delete_item',{ index }).done(()=>location.reload());
	});

	$('.ldbg-retry-item').on('click', function(e){
		e.preventDefault();
		const index = $(this).closest('tr').data('index');
		post('ldbg_retry_item',{ index }).done(()=>location.reload());
	});

	$('#ldbg-export-queue').on('click', function(e){
		e.preventDefault();
		post('ldbg_export_queue').done((res)=>{
			if(res.success){
				const blob = new Blob([JSON.stringify(res.data.json,null,2)],{type:'application/json'});
				const url = URL.createObjectURL(blob);
				const a = document.createElement('a');
				a.href = url; a.download = 'ldbg-queue.json'; a.click();
				URL.revokeObjectURL(url);
			}
		});
	});

	$('#ldbg-test-connection').on('click', function(e){
		e.preventDefault();
		const out = $('#ldbg-test-output').text('Testing…');
		post('ldbg_test_connection').done((res)=>{
			out.text(JSON.stringify(res, null, 2));
		}).fail((xhr)=>{ out.text(xhr.responseText || 'Request failed'); });
	});

	$('#ldbg-rotate-secret').on('click', function(e){
		e.preventDefault();
		post('ldbg_rotate_secret').done((res)=>{
			if(res.success){ $('#ldbg-rotate-output').text(res.data.secret); alert('Secret rotated. Don’t forget to update your receiver.'); }
		});
	});

	$('#ldbg-enqueue-payload').on('click', function(e){
		e.preventDefault();
		const payload = $('#ldbg-enqueue-json').val();
		post('ldbg_enqueue_payload',{ payload }).done(()=>location.reload());
	});

	$('#ldbg-schedule-cron').on('click', function(e){
		e.preventDefault();
		post('ldbg_schedule_cron').done(()=>location.reload());
	});

	// View JSON payload in a modal-ish prompt
	$('.ldbg-view-json').on('click', function(e){
		e.preventDefault();
		const tr = $(this).closest('tr');
		const idx = tr.data('index');
		try{
			const json = JSON.stringify(window.ldbgQueue && window.ldbgQueue[idx] ? window.ldbgQueue[idx] : null, null, 2);
			alert(json || 'N/A');
		}catch(err){ alert('N/A'); }
	});
})(jQuery);
