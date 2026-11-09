<?php

/**
 * Channel Audit page. Rendered by ChannelAuditController::index() between the
 * admin layout's header and footer. Everything after the first load goes
 * through api?action=channel_audit_*.
 *
 * @var array $rServers
 * @var list<array{id:int, name:string, spec:array}> $rStandards
 * @var list<array{id:int, name:string}> $rChannels
 * @var array $rDefaults
 * @var int $rMainID
 */

use XcVm\Core\Util\LayoutRenderer;

$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="card-title mb-0">Channel Audit</h5>
            <small class="text-body-secondary">Strict check of every video file against a standard, before a created channel is built from them. Nothing is changed: the report is yours to act on.</small>
        </div>
        <button type="button" class="btn btn-label-secondary btn-sm" id="ca-manage"><i class="icon-base ti tabler-adjustments me-1"></i>Standards</button>
    </div>
    <div class="card-body">
        <form id="ca-start" autocomplete="off">
            <div class="mb-3">
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="kind" id="ca-kind-folder" value="folder" checked>
                    <label class="form-check-label" for="ca-kind-folder">A folder (before creating a channel)</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="kind" id="ca-kind-channel" value="channel">
                    <label class="form-check-label" for="ca-kind-channel">An existing created channel</label>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-3 ca-folder">
                    <label class="form-label" for="ca-server">Server</label>
                    <select class="form-select" name="server_id" id="ca-server">
                        <?php foreach ($rServers as $rServer): ?>
                            <option value="<?= (int) $rServer['id'] ?>" <?= (int) $rServer['id'] === $rMainID ? 'selected' : '' ?>><?= $h($rServer['server_name'] ?? ('Server #' . $rServer['id'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5 ca-folder">
                    <label class="form-label" for="ca-folder">Folder</label>
                    <input type="text" class="form-control" name="folder" id="ca-folder" placeholder="/srv/media/channel">
                </div>
                <div class="col-md-8 ca-channel d-none">
                    <label class="form-label" for="ca-channel">Created channel</label>
                    <select class="form-select" name="stream_id" id="ca-channel">
                        <?php foreach ($rChannels as $rChannel): ?>
                            <option value="<?= (int) $rChannel['id'] ?>"><?= $h($rChannel['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="ca-standard">Standard</label>
                    <select class="form-select" name="standard_id" id="ca-standard"></select>
                </div>
            </div>
            <div class="form-check mt-3">
                <input class="form-check-input" type="checkbox" name="deep" value="1" id="ca-deep">
                <label class="form-check-label" for="ca-deep">Also decode every frame (finds damaged frames; takes about 1–3 minutes of CPU per hour of HD video)</label>
            </div>
            <div class="alert alert-danger mt-3 mb-0 d-none" id="ca-start-error"></div>
            <button type="submit" class="btn btn-primary mt-3"><i class="icon-base ti tabler-player-play me-1"></i>Start audit</button>
            <small class="text-body-secondary ms-2">Files on another server are read over the network.</small>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h5 class="card-title mb-0">Audits</h5></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr><th>#</th><th>Files of</th><th>Standard</th><th style="min-width:160px">Progress</th><th>Result</th><th>Started</th><th></th></tr>
            </thead>
            <tbody id="ca-jobs"><tr><td colspan="7" class="text-body-secondary">…</td></tr></tbody>
        </table>
    </div>
</div>

<div class="card mb-4 d-none" id="ca-report">
    <div class="card-header">
        <h5 class="card-title mb-1" id="ca-report-title"></h5>
        <small class="text-body-secondary" id="ca-report-standard"></small>
    </div>
    <div class="card-body pb-0">
        <div class="alert mb-3" id="ca-verdict"></div>
    </div>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>#</th><th>File</th><th>Duration</th><th>Result</th><th>Findings</th></tr></thead>
            <tbody id="ca-items"></tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="ca-standards-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Standards</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <table class="table table-sm mb-4">
                    <thead><tr><th>Name</th><th>Every file must be</th><th></th></tr></thead>
                    <tbody id="ca-standards"></tbody>
                </table>
                <form id="ca-standard-form" autocomplete="off">
                    <input type="hidden" name="id" value="0">
                    <h6 class="mb-3" id="ca-standard-form-title">New standard</h6>
                    <div class="row g-3">
                        <div class="col-md-12"><label class="form-label">Name</label><input class="form-control" name="name" maxlength="100" required></div>
                        <div class="col-md-2"><label class="form-label">Video codec</label><input class="form-control" name="video_codec" placeholder="h264"></div>
                        <div class="col-md-2"><label class="form-label">Profile <small class="text-body-secondary">(empty = any)</small></label><input class="form-control" name="video_profile" placeholder="High"></div>
                        <div class="col-md-2"><label class="form-label">Width</label><input class="form-control" type="number" name="width"></div>
                        <div class="col-md-2"><label class="form-label">Height</label><input class="form-control" type="number" name="height"></div>
                        <div class="col-md-2"><label class="form-label">Frame rate</label><input class="form-control" name="fps" placeholder="25 or 30000/1001"></div>
                        <div class="col-md-2"><label class="form-label">Scan</label><select class="form-select" name="scan"><option value="progressive">progressive</option><option value="interlaced">interlaced</option></select></div>
                        <div class="col-md-2"><label class="form-label">Pixel format</label><input class="form-control" name="pix_fmt" placeholder="yuv420p"></div>
                        <div class="col-md-2"><label class="form-label">SAR</label><input class="form-control" name="sar" placeholder="1:1"></div>
                        <div class="col-md-2"><label class="form-label">GOP <small class="text-body-secondary">(frames, 0 = any)</small></label><input class="form-control" type="number" name="gop"></div>
                        <div class="col-md-2"><label class="form-label">GOP rule</label><select class="form-select" name="gop_mode"><option value="exact">exactly</option><option value="max">at most (shorter = warning)</option></select></div>
                        <div class="col-md-2"><label class="form-label">Audio codec</label><input class="form-control" name="audio_codec" placeholder="aac"></div>
                        <div class="col-md-2"><label class="form-label">Sample rate</label><input class="form-control" type="number" name="audio_rate"></div>
                        <div class="col-md-2"><label class="form-label">Channels</label><input class="form-control" type="number" name="audio_channels"></div>
                        <div class="col-md-2"><label class="form-label">Audio tracks</label><input class="form-control" type="number" name="audio_streams"></div>
                        <div class="col-md-3"><label class="form-label">Max A/V start gap (ms)</label><input class="form-control" type="number" name="av_start_ms"></div>
                        <div class="col-md-3"><label class="form-label">Max A/V end gap (ms)</label><input class="form-control" type="number" name="av_end_ms"></div>
                        <div class="col-md-3"><label class="form-label">Tolerated timestamp glitches <small class="text-body-secondary">(per file)</small></label><input class="form-control" type="number" name="max_glitches"></div>
                        <div class="col-md-3"><label class="form-label">…each at most (ms)</label><input class="form-control" type="number" name="max_glitch_ms"></div>
                    </div>
                    <div class="alert alert-danger mt-3 mb-0 d-none" id="ca-standard-error"></div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Save standard</button>
                        <button type="button" class="btn btn-label-secondary" id="ca-standard-new">New</button>
                    </div>
                    <p class="text-body-secondary small mt-3 mb-0">Always checked as well, whatever the standard: one video track, the first frame a keyframe, timestamps in order, constant frame rate, no holes in the audio, and readable packets. Tolerated glitches (0 = none, strict) let files joined by stream copy pass with a warning for the few small timestamp seams they carry; anything above the count or the size still fails.</p>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
LayoutRenderer::renderFooter('admin');
?>
<script>
    (function() {
        var standards = <?= json_encode($rStandards) ?>,
            defaults = <?= json_encode($rDefaults) ?>,
            currentReport = 0,
            timer = null;

        function esc(s) {
            var d = document.createElement('div');
            d.textContent = (s == null ? '' : String(s));
            return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function clock(sec) {
            sec = Math.max(0, Math.round(sec || 0));
            var h = Math.floor(sec / 3600),
                m = Math.floor(sec % 3600 / 60),
                s = sec % 60;
            return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        }

        function describe(sp) {
            var v = sp.video_codec + (sp.video_profile ? ' ' + sp.video_profile : '') + ' ' + sp.width + 'x' + sp.height + ' ' + sp.fps + (sp.scan === 'progressive' ? 'p' : 'i') + ' ' + sp.pix_fmt + ' SAR ' + sp.sar + (parseInt(sp.gop, 10) > 0 ? ' GOP ' + (sp.gop_mode === 'max' ? '≤' : '') + sp.gop : '');
            var a = parseInt(sp.audio_streams, 10) === 0 ? 'no audio' : sp.audio_codec + ' ' + (sp.audio_rate / 1000) + 'k ' + sp.audio_channels + 'ch';
            return v + ' · ' + a + (parseInt(sp.max_glitches, 10) > 0 ? ' · tolerates ' + sp.max_glitches + ' glitch(es) ≤ ' + sp.max_glitch_ms + ' ms' : '');
        }

        function badge(status) {
            var map = {
                pass: ['success', 'PASS'],
                warn: ['warning', 'WARN'],
                fail: ['danger', 'FAIL'],
                error: ['danger', 'ERROR'],
                running: ['info', 'checking…'],
                queued: ['secondary', 'waiting']
            };
            var b = map[status] || ['secondary', status];
            return '<span class="badge bg-label-' + b[0] + '">' + esc(b[1]) + '</span>';
        }

        function errorBox(el, errors) {
            $(el).html((errors || ['Something went wrong.']).map(esc).join('<br>')).removeClass('d-none');
        }

        // ---- standards ----
        function renderStandards() {
            var sel = $('#ca-standard'),
                keep = sel.val();
            sel.html(standards.map(function(s) {
                return '<option value="' + s.id + '">' + esc(s.name) + '</option>';
            }).join(''));
            if (keep && standards.some(function(s) {
                    return String(s.id) === keep;
                })) {
                sel.val(keep);
            }
            $('#ca-standards').html(standards.length ? standards.map(function(s) {
                return '<tr><td>' + esc(s.name) + '</td><td><code>' + esc(describe(s.spec)) + '</code></td><td class="text-end text-nowrap">' +
                    '<button type="button" class="btn btn-sm btn-label-primary ca-std-edit" data-id="' + s.id + '">Edit</button> ' +
                    '<button type="button" class="btn btn-sm btn-label-danger ca-std-del" data-id="' + s.id + '">Delete</button></td></tr>';
            }).join('') : '<tr><td colspan="3" class="text-body-secondary">No standard yet: add one below.</td></tr>');
        }

        function fillStandardForm(s) {
            var f = $('#ca-standard-form');
            var spec = s ? s.spec : defaults;
            f.find('[name=id]').val(s ? s.id : 0);
            f.find('[name=name]').val(s ? s.name : '');
            $.each(spec, function(k, v) {
                f.find('[name=' + k + ']').val(v);
            });
            $('#ca-standard-form-title').text(s ? 'Edit "' + s.name + '"' : 'New standard');
            $('#ca-standard-error').addClass('d-none');
        }

        $('#ca-manage').on('click', function() {
            fillStandardForm(null);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('ca-standards-modal')).show();
        });
        $('#ca-standard-new').on('click', function() {
            fillStandardForm(null);
        });
        $('#ca-standards').on('click', '.ca-std-edit', function() {
            var id = $(this).data('id');
            fillStandardForm(standards.filter(function(s) {
                return s.id === id;
            })[0]);
        }).on('click', '.ca-std-del', function() {
            $.post('./api?action=channel_audit_standard_delete', {
                id: $(this).data('id')
            }, function(r) {
                if (r && r.result) {
                    standards = r.standards;
                    renderStandards();
                    fillStandardForm(null);
                }
            }, 'json');
        });
        $('#ca-standard-form').on('submit', function(e) {
            e.preventDefault();
            $.post('./api?action=channel_audit_standard_save', $(this).serialize(), function(r) {
                if (r && r.result) {
                    standards = r.standards;
                    renderStandards();
                    $('#ca-standard').val(String(r.id));
                    fillStandardForm(standards.filter(function(s) {
                        return s.id === r.id;
                    })[0]);
                } else {
                    errorBox('#ca-standard-error', r && r.errors);
                }
            }, 'json');
        });

        // ---- start ----
        $('input[name=kind]').on('change', function() {
            var channel = $('#ca-kind-channel').is(':checked');
            $('.ca-folder').toggleClass('d-none', channel);
            $('.ca-channel').toggleClass('d-none', !channel);
        });
        $('#ca-start').on('submit', function(e) {
            e.preventDefault();
            $('#ca-start-error').addClass('d-none');
            $.post('./api?action=channel_audit_start', $(this).serialize(), function(r) {
                if (r && r.result) {
                    loadJobs();
                    showReport(r.id);
                } else {
                    errorBox('#ca-start-error', r && r.errors);
                }
            }, 'json').fail(function() {
                errorBox('#ca-start-error');
            });
        });

        // ---- jobs ----
        function resultText(j) {
            if (j.status === 'failed') {
                return '<span class="text-danger">' + esc(j.error || 'failed') + '</span>';
            }
            if (j.status === 'cancelled') {
                return '<span class="text-body-secondary">cancelled</span>';
            }
            if (!j.done) {
                return '';
            }
            return '<span class="text-success">' + j.passed + ' pass</span>' +
                (j.warned > 0 ? ' · <span class="text-warning">' + j.warned + ' warn</span>' : '') +
                (j.failed > 0 ? ' · <span class="text-danger">' + j.failed + ' fail</span>' : '');
        }

        function loadJobs() {
            $.getJSON('./api?action=channel_audit_jobs', function(r) {
                if (!r || !r.result) {
                    return;
                }
                var active = false;
                $('#ca-jobs').html(r.jobs.length ? r.jobs.map(function(j) {
                    var live = j.status === 'running' || j.status === 'queued';
                    active = active || live;
                    var pct = j.total > 0 ? Math.round(100 * j.done / j.total) : 0;
                    return '<tr>' +
                        '<td>' + j.id + '</td>' +
                        '<td>' + (j.kind === 'channel' ? '<i class="icon-base ti tabler-device-tv me-1"></i>' : '<i class="icon-base ti tabler-folder me-1"></i>') + esc(j.label) + (parseInt(j.deep, 10) ? ' <span class="badge bg-label-info">decode</span>' : '') + '</td>' +
                        '<td>' + esc(j.standard_name) + '</td>' +
                        '<td><div class="progress" style="height:8px"><div class="progress-bar' + (live ? ' progress-bar-striped progress-bar-animated' : '') + '" style="width:' + pct + '%"></div></div><small>' + j.done + ' / ' + j.total + '</small></td>' +
                        '<td>' + resultText(j) + '</td>' +
                        '<td><small>' + (j.created_at ? new Date(j.created_at * 1000).toLocaleString() : '') + '</small></td>' +
                        '<td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-label-primary ca-view" data-id="' + j.id + '">Report</button> ' +
                        (live ? '<button type="button" class="btn btn-sm btn-label-warning ca-cancel" data-id="' + j.id + '">Cancel</button>' :
                            '<button type="button" class="btn btn-sm btn-label-danger ca-del" data-id="' + j.id + '">Delete</button>') +
                        '</td></tr>';
                }).join('') : '<tr><td colspan="7" class="text-body-secondary">No audit yet.</td></tr>');
                clearTimeout(timer);
                if (active) {
                    timer = setTimeout(function() {
                        loadJobs();
                        if (currentReport) {
                            showReport(currentReport, true);
                        }
                    }, 3000);
                }
            });
        }
        $('#ca-jobs').on('click', '.ca-view', function() {
            showReport($(this).data('id'));
        }).on('click', '.ca-cancel', function() {
            $.post('./api?action=channel_audit_cancel', {
                id: $(this).data('id')
            }, loadJobs, 'json');
        }).on('click', '.ca-del', function() {
            var id = $(this).data('id');
            $.post('./api?action=channel_audit_delete', {
                id: id
            }, function() {
                if (currentReport === id) {
                    currentReport = 0;
                    $('#ca-report').addClass('d-none');
                }
                loadJobs();
            }, 'json');
        });

        // ---- report ----
        function verdict(j) {
            var box = $('#ca-verdict').removeClass('alert-success alert-warning alert-danger alert-info alert-secondary');
            var n = j.total,
                link = j.kind === 'channel' ? ' <a href="./created_channel?id=' + parseInt(j.stream_id, 10) + '">Open the channel</a>' : ' <a href="./created_channel">Create a channel</a>';
            if (j.status === 'running' || j.status === 'queued') {
                box.addClass('alert-info').html('Checking… ' + j.done + ' of ' + n + ' files done.');
            } else if (j.status === 'failed') {
                box.addClass('alert-danger').html('The audit could not run: ' + esc(j.error || 'unknown error'));
            } else if (j.status === 'cancelled') {
                box.addClass('alert-secondary').html('Cancelled after ' + j.done + ' of ' + n + ' files.');
            } else if (j.failed > 0) {
                box.addClass('alert-danger').html('<strong>' + j.failed + ' of ' + n + ' files do not meet the standard.</strong> Normalize them before building the channel. ' +
                    'If you build it anyway, the panel stream-copies the files that match the most common format and re-encodes the others, and mismatched timing (frame rate, GOP, A/V gaps) carries into the channel as stalls, drift and restarts.' + link);
            } else if (j.warned > 0) {
                box.addClass('alert-warning').html('<strong>All ' + n + ' files meet the standard</strong>; ' + j.warned + ' have warnings worth a look. The channel can be built by stream copy.' + link);
            } else {
                box.addClass('alert-success').html('<strong>All ' + n + ' files meet the standard.</strong> The channel can be built by stream copy, without re-encoding.' + link);
            }
        }

        function facts(rep) {
            var f = rep && rep.facts,
                out = [];
            if (!f) {
                return '';
            }
            if (f.video) {
                out.push(esc(f.video.codec + (f.video.profile ? ' ' + f.video.profile : '') + ' ' + f.video.width + 'x' + f.video.height + ' ' + f.video.fps + ' (avg ' + f.video.avg_fps + ') ' + (f.video.scan || 'scan ?') + ' ' + f.video.pix_fmt + ' SAR ' + (f.video.sar || '?')));
            }
            if (f.audio) {
                out.push(esc(f.audio.codec + ' ' + f.audio.rate + ' Hz ' + f.audio.channels + 'ch ' + (f.audio.layout || '')));
            }
            if (f.gops) {
                out.push('GOPs: ' + esc(Object.keys(f.gops).map(function(k) {
                    return k + ' ×' + f.gops[k];
                }).join(', ')));
            }
            if (f.format) {
                out.push(esc(f.format) + (f.size ? ' · ' + (f.size / 1048576).toFixed(1) + ' MB' : ''));
            }
            return '<details class="mt-1"><summary class="small text-body-secondary">details</summary><small class="text-body-secondary">' + out.join('<br>') + '</small></details>';
        }

        function showReport(id, quiet) {
            currentReport = id;
            $.getJSON('./api?action=channel_audit_report&id=' + encodeURIComponent(id), function(r) {
                if (!r || !r.result) {
                    return;
                }
                var j = r.job;
                $('#ca-report').removeClass('d-none');
                $('#ca-report-title').text('Audit #' + j.id + ' — ' + j.label);
                $('#ca-report-standard').text('Standard "' + j.standard_name + '": ' + j.standard + (parseInt(j.deep, 10) ? ' · full decode' : ''));
                verdict(j);
                $('#ca-items').html(r.items.map(function(it) {
                    var rep = it.report,
                        issues = rep && rep.issues ? rep.issues : [];
                    var list = issues.length ? '<ul class="mb-0 ps-3">' + issues.map(function(i) {
                        return '<li class="' + (i[0] === 'fail' ? 'text-danger' : 'text-warning') + '"><strong>' + esc(i[1]) + '</strong>: ' + esc(i[2]) + '</li>';
                    }).join('') + '</ul>' : (it.status === 'pass' ? '<span class="text-success">meets the standard</span>' : '');
                    return '<tr class="' + (it.status === 'fail' || it.status === 'error' ? 'table-danger' : '') + '">' +
                        '<td>' + (parseInt(it.position, 10) + 1) + '</td>' +
                        '<td><div class="text-break">' + esc(it.name) + '</div><small class="text-body-secondary text-break">' + esc(it.source) + '</small></td>' +
                        '<td>' + (it.duration !== null ? clock(parseFloat(it.duration)) : '') + '</td>' +
                        '<td>' + badge(it.status) + '</td>' +
                        '<td>' + list + facts(rep) + '</td></tr>';
                }).join(''));
                if (!quiet) {
                    document.getElementById('ca-report').scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            });
        }

        renderStandards();
        loadJobs();
    })();
</script>
</body>

</html>
