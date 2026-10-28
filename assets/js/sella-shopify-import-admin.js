(function ($) {
  'use strict';

  /**
   * Shopify import screen (inc/sella-shopify-import.php): each job panel runs
   * its import one step at a time until the server says it is done, the
   * button is pressed again, or a step fails.
   */
  var cfg = window.sellaSiAdmin || {};
  var text = cfg.text || {};
  var MAX_RETRIES = 3;
  /* A breath between steps, so the import never keeps the database busy non-stop. */
  var PAUSE = 1500;

  function initJob(el) {
    var $job = $(el);
    var name = $job.data('si-job');
    var $start = $job.find('[data-si-start]');
    var $stop = $job.find('[data-si-stop]');
    var $restart = $job.find('[data-si-restart]');
    var $status = $job.find('[data-si-status]');
    var $log = $job.find('[data-si-log]');
    var running = false;
    var stopping = false;
    var retries = 0;

    function setRunning(on) {
      running = on;
      $start.prop('disabled', on);
      $restart.prop('disabled', on);
      $stop.prop('hidden', !on);
    }

    function show(data) {
      ['imported', 'updated', 'skipped', 'failed'].forEach(function (key) {
        $job.find('[data-si-count="' + key + '"]').text((data[key] || 0).toLocaleString('he-IL'));
      });
      (data.log || []).forEach(function (line) {
        $('<li>').addClass('is-' + line[0]).text(line[1]).prependTo($log);
      });
      $log.children().slice(200).remove();
    }

    function step(restart) {
      $.post(cfg.ajaxUrl, {
        action: 'sella_si_step',
        nonce: cfg.nonce,
        job: name,
        restart: restart ? 1 : 0
      })
        .done(function (res) {
          retries = 0;
          var data = (res && res.data) || {};
          show(data);

          if (!res || !res.success) {
            setRunning(false);
            $status.text(text.error + (data.message ? ' ' + data.message : ''));
            return;
          }
          if (data.done) {
            setRunning(false);
            $status.text(text.finished);
            return;
          }
          if (stopping) {
            setRunning(false);
            $status.text(text.stopped);
            return;
          }
          if (data.wait) {
            // The old store asked to slow down, or the previous step is still
            // running on the server: the same step runs again after the pause.
            $status.text(data.busy ? text.busy : text.waiting);
            setTimeout(function () {
              if (stopping) {
                setRunning(false);
                $status.text(text.stopped);
                return;
              }
              $status.text(text.running);
              step(false);
            }, data.wait * 1000);
            return;
          }
          $status.text(data.note || (data.total ? text.running + ' ' + (data.imported + data.updated + data.skipped + data.failed) + ' / ' + data.total : text.running));
          setTimeout(function () { step(false); }, PAUSE);
        })
        .fail(function () {
          // A step that ran past the server's time limit: the work it did is
          // saved, so asking again continues from there. The wait is long, so
          // a server that is struggling gets the time to recover.
          if (retries < MAX_RETRIES && !stopping) {
            retries++;
            $status.text(text.retrying);
            setTimeout(function () { step(false); }, 15000 * retries);
            return;
          }
          retries = 0;
          setRunning(false);
          $status.text(text.error);
        });
    }

    function start(restart) {
      if (running) {
        return;
      }
      stopping = false;
      setRunning(true);
      if (restart) {
        $log.empty();
      }
      $status.text(text.running);
      step(restart);
    }

    $start.on('click', function () { start(false); });
    $restart.on('click', function (e) {
      e.preventDefault();
      start(true);
    });
    $stop.on('click', function () {
      stopping = true;
      $stop.prop('hidden', true);
      $status.text(text.stopping);
    });
  }

  /**
   * The orders CSV goes up in parts (cfg.chunk bytes each), so a file with
   * any number of orders gets past the server's upload limit. A part whose
   * answer was lost is not sent again blindly: the server says how much of
   * the file it holds, and the upload goes on from there.
   */
  function initUpload(form) {
    var $form = $(form);
    var input = form.querySelector('input[type="file"]');
    var $button = $form.find('button[type="submit"]');
    var $status = $('<p class="sella-si-upload-status" role="status"></p>').insertAfter($form);

    if (!input || !window.FormData || !window.Blob || !window.Blob.prototype.slice || !cfg.chunk) {
      return; // The plain form post, for small files.
    }

    $form.on('submit', function (event) {
      var file = input.files && input.files[0];
      if (!file) {
        return; // The server answers that no file came.
      }
      event.preventDefault();

      var upload = '';
      for (var i = 0; i < 24; i++) {
        upload += 'abcdefghijklmnopqrstuvwxyz0123456789'.charAt(Math.floor(Math.random() * 36));
      }
      var newest = $form.find('[name="newest"]').val() || '';
      var retries = 0;

      function failed(message) {
        $button.prop('disabled', false);
        $status.text(text.uploadFailed + (message ? ' ' + message : ''));
      }

      function send(offset) {
        var end = Math.min(offset + cfg.chunk, file.size);
        var data = new FormData();
        data.append('action', 'sella_si_csv_chunk');
        data.append('nonce', cfg.nonce);
        data.append('upload', upload);
        data.append('offset', offset);
        data.append('last', end >= file.size ? 1 : 0);
        data.append('newest', newest);
        if (end > offset) {
          data.append('chunk', file.slice(offset, end), 'part.csv');
        }
        $status.text(text.uploading + ' ' + (file.size ? Math.floor(offset / file.size * 100) : 0) + '%');

        $.ajax({ url: cfg.ajaxUrl, type: 'POST', data: data, processData: false, contentType: false })
          .done(function (res) {
            retries = 0;
            var d = (res && res.data) || {};
            if (res && res.success && d.done) {
              $status.text(text.uploading + ' 100%');
              window.location.href = cfg.uploadedUrl;
            } else if (res && res.success) {
              send(d.offset);
            } else if (typeof d.offset === 'number' && d.offset <= file.size) {
              send(d.offset);
            } else {
              failed(d.message);
            }
          })
          .fail(function () {
            if (retries < 5) {
              retries++;
              $status.text(text.uploadRetry);
              setTimeout(function () { send(offset); }, 3000 * retries);
              return;
            }
            failed();
          });
      }

      $button.prop('disabled', true);
      send(0);
    });
  }

  $(function () {
    $('[data-si-job]').each(function () { initJob(this); });
    $('.sella-si-upload').each(function () { initUpload(this); });
  });
})(jQuery);
