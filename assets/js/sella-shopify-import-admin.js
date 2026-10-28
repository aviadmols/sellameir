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
          $status.text(data.total ? text.running + ' ' + (data.imported + data.updated + data.skipped + data.failed) + ' / ' + data.total : text.running);
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

  $(function () {
    $('[data-si-job]').each(function () { initJob(this); });
  });
})(jQuery);
