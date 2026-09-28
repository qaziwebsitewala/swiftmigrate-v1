/* SwiftMigrate admin orchestrator. No dependencies. */
(function () {
	'use strict';

	var CFG = window.swiftmigrateAdmin || {};
	// WordPress defines ajaxurl on every admin screen; use it if localized settings are missing.
	CFG.ajax = CFG.ajax || window.ajaxurl || '';
	// wp_localize_script() delivers every scalar as a string.
	CFG.workers = parseInt(CFG.workers, 10) || 4;
	CFG.page = parseInt(CFG.page, 10) || 50;
	CFG.backup = CFG.backup === true || CFG.backup === '1';
	var BACKOFF = [1, 2, 4, 8, 15, 30]; // Seconds between retries of a failed request.

	// ------------------------------------------------------------ helpers

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
	function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

	/**
	 * Server messages are HTML-escaped in PHP. They are always shown with
	 * textContent, so decode the entities once to display them as plain text.
	 */
	function plain(s) {
		var t = document.createElement('textarea');
		t.innerHTML = String(s == null ? '' : s);
		return t.value;
	}

	function FatalError(message, code) {
		this.message = message;
		this.code = code;
		this.fatal = true;
	}
	FatalError.prototype = Object.create(Error.prototype);

	/** One admin-ajax call. Rejects with {fatal} for auth problems, plain Error otherwise. */
	function call(action, data) {
		var fd = new FormData();
		fd.append('action', 'swiftmigrate_' + action);
		fd.append('nonce', CFG.nonce);
		Object.keys(data || {}).forEach(function (k) {
			var v = data[k];
			if (Array.isArray(v)) {
				v.forEach(function (row, i) {
					if (Array.isArray(row)) {
						row.forEach(function (cell, j) { fd.append(k + '[' + i + '][' + j + ']', cell); });
					} else {
						fd.append(k + '[]', row);
					}
				});
			} else if (v !== undefined && v !== null) {
				fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
			}
		});
		return fetch(CFG.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (res) {
			return res.text().then(function (text) {
				var json = null;
				try { json = JSON.parse(text); } catch (e) { /* not JSON */ }
				if (json && json.success) { return json.data; }
				if (json && json.data && json.data.message) {
					var msg = plain(json.data.message);
					if (json.data.code === 'auth' || json.data.code === 'nonce') {
						throw new FatalError(msg, json.data.code);
					}
					var err = new Error(msg);
					err.code = json.data.code;
					throw err;
				}
				if (text.trim() === '0' || text.trim() === '-1') {
					throw new FatalError('Your WordPress session has expired. Reload the page and log in again.', 'auth');
				}
				throw new Error('HTTP ' + res.status + (res.status >= 500 ? ' (server error or timeout)' : ''));
			});
		});
	}

	/** call() with retries and exponential backoff. onRetry(attempt, err) is optional. */
	function retry(action, data, onRetry, shouldStop) {
		var attempt = 0;
		function go() {
			return call(action, data).catch(function (err) {
				// Permanent answers from the server are not retried.
				if (err.fatal || (err.code >= 400 && err.code < 500 && err.code !== 408 && err.code !== 429)) { throw err; }
				if (attempt >= BACKOFF.length || (shouldStop && shouldStop())) { throw err; }
				var wait = BACKOFF[attempt++];
				if (onRetry) { onRetry(attempt, err, wait); }
				return sleep(wait * 1000).then(go);
			});
		}
		return go();
	}

	function fmtBytes(b) {
		b = Number(b) || 0;
		var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
		while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
		return (i === 0 ? b : b.toFixed(b >= 100 ? 0 : 1)) + ' ' + u[i];
	}
	function fmtNum(n) { return (Number(n) || 0).toLocaleString(); }
	function fmtTime(s) {
		if (!isFinite(s) || s <= 0) { return '—'; }
		s = Math.round(s);
		if (s < 60) { return s + 's'; }
		if (s < 3600) { return Math.floor(s / 60) + 'm ' + (s % 60) + 's'; }
		return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm';
	}
	function host(url) { return String(url || '').replace(/^https?:\/\//, '').replace(/\/$/, ''); }

	// Bitmap of finished jobs (1 bit per job), mirrored to the server for resume.
	function Bitmap(n, b64) {
		this.n = n;
		this.bytes = new Uint8Array(Math.ceil(Math.max(1, n) / 8));
		if (b64) {
			try {
				var bin = atob(b64);
				if (bin.length === this.bytes.length) {
					for (var i = 0; i < bin.length; i++) { this.bytes[i] = bin.charCodeAt(i); }
				}
			} catch (e) { /* start clean */ }
		}
	}
	Bitmap.prototype.get = function (i) { return (this.bytes[i >> 3] >> (i & 7)) & 1; };
	Bitmap.prototype.set = function (i) { this.bytes[i >> 3] |= (1 << (i & 7)); };
	Bitmap.prototype.clear = function (i) { this.bytes[i >> 3] &= ~(1 << (i & 7)); };
	Bitmap.prototype.count = function () {
		var c = 0;
		for (var i = 0; i < this.n; i++) { c += this.get(i); }
		return c;
	};
	Bitmap.prototype.b64 = function () {
		var s = '';
		for (var i = 0; i < this.bytes.length; i += 8192) {
			s += String.fromCharCode.apply(null, this.bytes.subarray(i, i + 8192));
		}
		return btoa(s);
	};

	// ------------------------------------------------------------ Send page

	function SendApp(root) {
		this.root = root;
		this.st = null;          // Last status from the server.
		this.bm = null;          // Done bitmap.
		this.paused = false;
		this.running = false;
		this.bytes = 0;
		this.files = 0;
		this.logLines = [];
		this.bind();
		this.init();
	}

	SendApp.prototype.el = function (name) { return $('[data-bind="' + name + '"]', this.root); };
	SendApp.prototype.set = function (name, text) { var e = this.el(name); if (e) { e.textContent = text; } };
	SendApp.prototype.btn = function (act) { return $('[data-act="' + act + '"]', this.root); };

	SendApp.prototype.stage = function (name) {
		this.root.setAttribute('data-state', name);
		$$('.smg-stage', this.root).forEach(function (s) { s.hidden = s.getAttribute('data-stage') !== name; });
		window.scrollTo({ top: 0, behavior: 'smooth' });
	};

	SendApp.prototype.bind = function () {
		var self = this;
		this.root.addEventListener('click', function (e) {
			var b = e.target.closest('[data-act]');
			if (!b || b.disabled) { return; }
			var act = b.getAttribute('data-act');
			var fn = {
				connect: self.connect,
				'add-pair': function () { self.addPair('', ''); },
				start: self.start,
				reconnect: function () { self.stage('connect'); },
				restore: self.restore,
				pause: self.pause,
				resume: self.resume,
				cancel: self.cancel,
				purge: self.purge,
				rollback: self.rollback,
				'new': function () { self.reset(); self.stage('connect'); }
			}[act];
			if (fn) { e.preventDefault(); fn.call(self, b); }
		});
		var confirmBox = $('input[name="confirm"]', this.root);
		confirmBox.addEventListener('change', function () { self.updateStart(); });
	};

	SendApp.prototype.inline = function (text, kind) {
		var s = $('[data-stage="connect"] .smg-inline-status', this.root);
		s.textContent = text || '';
		s.className = 'smg-inline-status' + (kind ? ' is-' + kind : '');
	};

	SendApp.prototype.init = function () {
		var self = this;
		call('status').then(function (st) {
			self.st = st;
			self.autorestore = !!st.autorestore;
			switch (st.phase) {
				case 'scan':
				case 'transfer':
				case 'verify':
				case 'restore':
					self.showRun(st);
					self.setPaused(true);
					self.warn('This migration was interrupted. Press Resume to continue exactly where it stopped.');
					break;
				case 'ready':
					self.showRun(st);
					self.showReady();
					break;
				case 'done':
					self.showDone(st);
					break;
			}
		}).catch(function () { /* stay on connect */ });
	};

	SendApp.prototype.reset = function () {
		this.st = null;
		this.bm = null;
		this.paused = false;
		this.running = false;
		this.bytes = 0;
		this.files = 0;
		this.logLines = [];
		this.root.classList.remove('is-complete', 'is-running');
	};

	// -- connect

	SendApp.prototype.connect = function (b) {
		var self = this;
		var key = $('#smg-key', this.root).value.trim();
		if (!key) { this.inline('Paste the key from the destination site first.', 'error'); return; }
		b.disabled = true;
		this.inline('Connecting…');
		call('connect', { key: key }).then(function (r) {
			self.inline('');
			self.conn = r;
			self.set('local', host(r.local.home));
			self.set('peer', host(r.peer.home));
			self.set('peerhost', host(r.peer.home));
			self.set('backupnote', CFG.backup
				? 'A rollback copy of the destination is kept until you delete it.'
				: 'No rollback copy will be kept (turned off in Settings).');
			var ul = self.el('checks');
			ul.innerHTML = '';
			self.blocked = false;
			(r.checks || []).forEach(function (c) {
				var li = document.createElement('li');
				li.className = 'is-' + c.level;
				var t = document.createElement('b'); t.textContent = c.label;
				var d = document.createElement('span'); d.textContent = c.detail;
				li.appendChild(t); li.appendChild(d);
				ul.appendChild(li);
				if (c.level === 'error') { self.blocked = true; }
			});
			if (!r.direct) {
				var li = document.createElement('li');
				li.className = 'is-warn';
				li.innerHTML = '<b>Endpoint</b><span></span>';
				li.lastChild.textContent = 'Using the ?rest_route= address because /wp-json/ is not reachable on the destination. This works the same way.';
				ul.appendChild(li);
			}
			if (!$('.smg-pair', self.el('pairs'))) { self.addPair('', ''); }
			self.updateStart();
			self.stage('preflight');
		}).catch(function (err) {
			self.inline(err.message, 'error');
		}).then(function () { b.disabled = false; });
	};

	SendApp.prototype.addPair = function (from, to) {
		var row = document.createElement('div');
		row.className = 'smg-pair';
		row.innerHTML = '<input type="text" placeholder="Find" aria-label="Find"><i aria-hidden="true">→</i><input type="text" placeholder="Replace with" aria-label="Replace with"><button type="button" aria-label="Remove">×</button>';
		var ins = row.querySelectorAll('input');
		ins[0].value = from; ins[1].value = to;
		row.querySelector('button').addEventListener('click', function () { row.remove(); });
		this.el('pairs').appendChild(row);
	};

	SendApp.prototype.updateStart = function () {
		this.btn('start').disabled = !$('input[name="confirm"]', this.root).checked || !!this.blocked;
	};

	SendApp.prototype.opt = function (name) {
		var i = $('[data-stage="preflight"] input[name="' + name + '"]', this.root);
		return i && i.checked ? 1 : 0;
	};

	// -- start

	SendApp.prototype.start = function (b) {
		var self = this;
		var pairs = $$('.smg-pair', this.el('pairs')).map(function (row) {
			var i = row.querySelectorAll('input');
			return [i[0].value, i[1].value];
		}).filter(function (p) { return p[0].trim() !== ''; });
		var data = { pairs: pairs };
		['db', 'uploads', 'themes', 'plugins', 'core', 'other', 'autorestore'].forEach(function (k) { data[k] = self.opt(k); });
		if (!data.db && !data.uploads && !data.themes && !data.plugins && !data.core && !data.other) {
			alert('Choose at least one thing to move.');
			return;
		}
		this.autorestore = !!this.opt('autorestore');
		b.disabled = true;
		call('start', data).then(function (st) {
			self.reset();
			self.st = st;
			self.showRun(st);
			self.loop();
		}).catch(function (err) {
			alert(err.message);
		}).then(function () { b.disabled = false; });
	};

	// -- running UI

	SendApp.prototype.showRun = function (st) {
		this.set('peer2', host(st.peer));
		this.stage('run');
		this.el('ready').hidden = true;
		this.el('error').hidden = true;
		this.btn('pause').hidden = false;
		this.btn('resume').hidden = true;
		this.btn('cancel').hidden = false;
		this.bytes = st.bytes_done || 0;
		this.files = st.files_done || 0;
		this.renderLog(st.log);
		this.step(st.phase);
		this.render();
	};

	SendApp.prototype.step = function (phase) {
		var order = ['scan', 'transfer', 'verify', 'restore'];
		var map = { ready: 'restore', done: 'done' };
		var cur = map[phase] || phase;
		var idx = cur === 'done' ? 4 : order.indexOf(cur);
		$$('li', this.el('stepper')).forEach(function (li, i) {
			li.classList.toggle('is-done', i < idx || (phase === 'ready' && i < 3));
			li.classList.toggle('is-current', i === idx && phase !== 'ready');
			if (i === idx) { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); }
		});
	};

	/** Maps phase progress onto a single overall percentage. */
	SendApp.prototype.progress = function (phase, frac) {
		var span = { scan: [0, 4], transfer: [4, 88], verify: [88, 92], ready: [92, 92], restore: [92, 100], done: [100, 100] }[phase] || [0, 0];
		return span[0] + (span[1] - span[0]) * Math.max(0, Math.min(1, frac || 0));
	};

	SendApp.prototype.bar = function (pct, label) {
		pct = Math.max(0, Math.min(100, pct));
		var shown = pct >= 99.95 ? '100%' : pct.toFixed(pct < 10 ? 1 : 0) + '%';
		this.set('pct', shown);
		this.el('bar').style.width = pct + '%';
		this.el('bar').parentNode.setAttribute('aria-valuenow', Math.round(pct));
		this.el('track').style.width = pct + '%';
		if (label) { this.set('label', label); }
	};

	SendApp.prototype.render = function () {
		var st = this.st || {};
		this.set('bytes', fmtBytes(this.bytes) + (st.bytes_total ? ' / ' + fmtBytes(st.bytes_total) + ' files' : ''));
		this.set('files', st.files_total ? fmtNum(this.files) + ' / ' + fmtNum(st.files_total) : '—');
		if (st.warning) { this.warn(st.warning); }
	};

	SendApp.prototype.warn = function (msg) {
		var w = this.el('warning');
		w.textContent = msg;
		w.hidden = !msg;
	};

	SendApp.prototype.fail = function (msg) {
		var e = this.el('error');
		e.textContent = msg;
		e.hidden = false;
		this.root.classList.remove('is-running');
		this.setPaused(true);
		this.log('ERROR ' + msg);
	};

	SendApp.prototype.log = function (line) {
		var t = new Date().toLocaleTimeString();
		this.logLines.push('[' + t + '] ' + line);
		if (this.logLines.length > 400) { this.logLines.shift(); }
		this.set('log', this.logLines.join('\n'));
	};

	SendApp.prototype.renderLog = function (lines) {
		if (lines && lines.length) {
			this.logLines = lines.slice();
			this.set('log', this.logLines.join('\n'));
		}
	};

	SendApp.prototype.setPaused = function (p) {
		this.paused = p;
		this.btn('pause').hidden = p;
		this.btn('resume').hidden = !p;
		if (p) { this.set('speed', '—'); this.set('eta', '—'); }
	};

	SendApp.prototype.pause = function () {
		this.setPaused(true);
		this.log('Pausing after the requests in flight finish…');
	};

	SendApp.prototype.resume = function () {
		this.el('error').hidden = true;
		this.warn(this.st && this.st.warning ? this.st.warning : '');
		this.setPaused(false);
		if (!this.running) { this.loop(); }
	};

	SendApp.prototype.cancel = function () {
		var self = this;
		if (!confirm('Cancel this migration? Data already sent to the destination is discarded. The destination site itself is not changed unless the restore has started.')) { return; }
		this.setPaused(true);
		var wait = function () { return self.running ? sleep(300).then(wait) : Promise.resolve(); };
		wait().then(function () { return call('cancel'); }).then(function () {
			self.reset();
			self.stage('connect');
			self.inline('Migration cancelled.', 'ok');
		}).catch(function (err) { self.fail(err.message); });
	};

	// -- main loop: runs whatever phase the server is in, until done or paused

	SendApp.prototype.loop = function () {
		var self = this;
		if (this.running) { return; }
		this.running = true;
		this.paused = false;
		this.btn('pause').hidden = false;
		this.btn('resume').hidden = true;
		this.root.classList.add('is-running');

		var run = function () {
			if (self.paused) { return Promise.resolve('paused'); }
			var ph = self.st.phase;
			self.step(ph);
			if (ph === 'scan') { return self.scan().then(run); }
			if (ph === 'transfer') { return self.transfer().then(run); }
			if (ph === 'verify') { return self.verify().then(run); }
			if (ph === 'ready') {
				if (self.autorestore) { return self.setPhase('restore').then(run); }
				self.showReady();
				return Promise.resolve('ready');
			}
			if (ph === 'restore') { return self.restoreLoop().then(run); }
			if (ph === 'done') { self.showDone(self.st); return Promise.resolve('done'); }
			return Promise.resolve(ph);
		};

		run().catch(function (err) {
			if (err && err.fatal) {
				self.fail(err.message);
			} else {
				self.fail((err && err.message ? err.message : String(err)) + ' — press Resume to try again; nothing already transferred is lost.');
			}
		}).then(function () {
			self.running = false;
			if (self.paused) { self.root.classList.remove('is-running'); }
		});
	};

	SendApp.prototype.setPhase = function (ph) {
		var self = this;
		return retry('phase', { phase: ph }).then(function (st) { self.st = st; return st; });
	};

	SendApp.prototype.scan = function () {
		var self = this;
		this.bar(this.progress('scan', 0.1), 'Scanning files…');
		var step = function () {
			if (self.paused) { return Promise.resolve(); }
			return retry('scan', {}, function (a, e, w) { self.log('Scan request failed (' + e.message + '), retrying in ' + w + 's'); }).then(function (st) {
				self.st = st;
				self.renderLog(st.log);
				self.set('files', fmtNum(st.files_total) + ' found');
				self.set('bytes', fmtBytes(st.bytes_total));
				self.bar(self.progress('scan', st.phase === 'scan' ? 0.5 : 1), 'Scanning files… ' + fmtNum(st.files_total) + ' found');
				if (st.phase === 'scan') { return step(); }
			});
		};
		return step();
	};

	/** Worker pool over every job whose bit is not set. */
	SendApp.prototype.transfer = function () {
		var self = this;
		var st = this.st;
		var total = st.jobs;
		if (!this.bm || this.bm.n !== total) {
			var p = st.bitmap !== undefined ? Promise.resolve(st) : retry('status', {});
			return p.then(function (s) {
				self.bm = new Bitmap(total, s.bitmap || '');
				self.st.bitmap = s.bitmap || '';
				return self.transfer();
			});
		}
		var todo = [];
		for (var i = 0; i < total; i++) { if (!this.bm.get(i)) { todo.push(i); } }
		if (!todo.length) { return this.setPhase('verify'); }
		this.log('Transferring ' + fmtNum(todo.length) + ' of ' + fmtNum(total) + ' chunks with ' + (st.workers || CFG.workers) + ' parallel streams');
		return this.pool(todo).then(function () {
			if (self.paused) { return; }
			return self.checkpoint().then(function () { return self.setPhase('verify'); });
		});
	};

	SendApp.prototype.pool = function (ids) {
		var self = this;
		var workers = Math.max(1, Math.min(ids.length, (this.st && this.st.workers) || CFG.workers || 4));
		var next = 0;
		var failure = null;
		var doneCount = this.bm.count();
		var total = this.bm.n;
		var t0 = Date.now();
		var sample = { t: Date.now(), bytes: this.bytes, jobs: doneCount };
		var speed = 0, jobRate = 0;
		var lastCp = Date.now();

		var tick = setInterval(function () {
			var now = Date.now();
			var dt = (now - sample.t) / 1000;
			if (dt < 1.5) { return; }
			var bps = (self.bytes - sample.bytes) / dt;
			var jps = (doneCount - sample.jobs) / dt;
			speed = speed ? speed * 0.7 + bps * 0.3 : bps;
			jobRate = jobRate ? jobRate * 0.7 + jps * 0.3 : jps;
			sample = { t: now, bytes: self.bytes, jobs: doneCount };
			if (!self.paused) {
				self.set('speed', fmtBytes(speed) + '/s');
				self.set('eta', (now - t0) > 4000 && jobRate > 0 ? fmtTime((total - doneCount) / jobRate) : 'estimating…');
			}
			if (now - lastCp > 5000) { lastCp = now; self.checkpoint().catch(function () {}); }
		}, 1000);

		var update = function () {
			self.bar(self.progress('transfer', doneCount / Math.max(1, total)), 'Transferring… ' + fmtNum(doneCount) + ' / ' + fmtNum(total) + ' chunks');
			self.render();
		};
		update();

		var runJob = function (n, cursor) {
			return retry('job', { n: n, cursor: cursor ? JSON.stringify(cursor) : '' }, function (a, e, w) {
				self.log('Chunk #' + n + ' failed (' + e.message + '), retry ' + a + '/' + BACKOFF.length + ' in ' + w + 's');
			}, function () { return !!failure; }).then(function (r) {
				self.bytes += Number(r.bytes) || 0;
				self.files += Number(r.files) || 0;
				if (!r.done) { return runJob(n, r.cursor); } // Large table continues in the next request.
			});
		};

		var worker = function () {
			if (self.paused || failure || next >= ids.length) { return Promise.resolve(); }
			var n = ids[next++];
			return runJob(n, null).then(function () {
				self.bm.set(n);
				doneCount++;
				update();
				return worker();
			}, function (err) {
				failure = failure || err;
			});
		};

		var list = [];
		for (var w = 0; w < workers; w++) { list.push(worker()); }
		return Promise.all(list).then(function () {
			clearInterval(tick);
			return self.checkpoint().catch(function () {});
		}).then(function () {
			if (failure) { throw failure; }
		});
	};

	SendApp.prototype.checkpoint = function () {
		if (!this.bm) { return Promise.resolve(); }
		return call('checkpoint', { bitmap: this.bm.b64(), bytes: this.bytes, files: this.files });
	};

	/** Ask the destination to confirm every chunk; resend whatever is missing. */
	SendApp.prototype.verify = function () {
		var self = this;
		var pages = this.st.pages || Math.ceil(this.st.jobs / (CFG.page || 50));
		var bad = [];
		var page = 0;
		this.set('speed', '—');
		this.set('eta', '—');
		this.log('Verifying ' + fmtNum(this.st.jobs) + ' chunks on the destination…');

		var verifyPages = function () {
			var inFlight = [];
			var nextPage = function () {
				if (self.paused || page >= pages) { return Promise.resolve(); }
				var p = page++;
				return retry('verify', { page: p }).then(function (r) {
					bad = bad.concat(r.bad || []);
					self.bar(self.progress('verify', page / Math.max(1, pages) * 0.8), 'Verifying on destination… ' + Math.min(page, pages) + ' / ' + pages);
					return nextPage();
				});
			};
			for (var i = 0; i < Math.min(3, pages); i++) { inFlight.push(nextPage()); }
			return Promise.all(inFlight);
		};

		var repair = function (ids, pass) {
			if (!ids.length) { return Promise.resolve(); }
			if (pass > 3) {
				throw new Error(ids.length + ' chunk(s) still failed verification after 3 repair passes. Check the destination\'s disk space and the activity log.');
			}
			self.log(ids.length + ' chunk(s) missing or incomplete on the destination — resending (pass ' + pass + ')');
			return retry('verify', { pass: 1 }).then(function () {
				if (!self.bm || self.bm.n !== self.st.jobs) { self.bm = new Bitmap(self.st.jobs, self.st.bitmap || ''); }
				ids.forEach(function (n) { self.bm.clear(n); });
				return self.pool(ids);
			}).then(function () {
				if (self.paused) { return; }
				var again = [];
				var groups = [];
				for (var i = 0; i < ids.length; i += (CFG.page || 50)) { groups.push(ids.slice(i, i + (CFG.page || 50))); }
				return groups.reduce(function (p, g) {
					return p.then(function () {
						return retry('verify', { ids: JSON.stringify(g) }).then(function (r) { again = again.concat(r.bad || []); });
					});
				}, Promise.resolve()).then(function () { return repair(again, pass + 1); });
			});
		};

		return verifyPages().then(function () {
			if (self.paused) { return; }
			return repair(bad, 1);
		}).then(function () {
			if (self.paused) { return; }
			self.log('All chunks verified on the destination.');
			return self.setPhase('ready');
		});
	};

	SendApp.prototype.showReady = function () {
		this.step('ready');
		this.bar(this.progress('ready', 1), 'Verified — ready to restore');
		this.el('ready').hidden = false;
		this.btn('pause').hidden = true;
		this.btn('resume').hidden = true;
		this.root.classList.remove('is-running');
		this.set('speed', '—');
		this.set('eta', '—');
	};

	SendApp.prototype.restore = function (b) {
		var self = this;
		b.disabled = true;
		this.el('ready').hidden = true;
		var waitIdle = function () { return self.running ? sleep(200).then(waitIdle) : Promise.resolve(); };
		waitIdle().then(function () { return self.setPhase('restore'); }).then(function () {
			self.loop();
		}).catch(function (err) { self.fail(err.message); }).then(function () { b.disabled = false; });
	};

	SendApp.prototype.restoreLoop = function () {
		var self = this;
		this.btn('cancel').hidden = true; // Rollback, not cancel, once files move.
		this.log('Restoring on the destination…');
		var step = function () {
			if (self.paused) { return Promise.resolve(); }
			return retry('remote', { what: 'restore' }, function (a, e, w) {
				self.log('Restore request failed (' + e.message + '), retrying in ' + w + 's');
			}).then(function (r) {
				var n = (r.steps || []).length || 6;
				var frac = r.done ? 1 : ((r.index || 0) + (r.step_pct || 0) / 100) / n;
				self.bar(self.progress('restore', frac), r.label || 'Restoring…');
				if (r.done) {
					self.log('Restore complete.');
					return retry('status', {}).then(function (st) { self.st = st; });
				}
				return step();
			});
		};
		return step();
	};

	SendApp.prototype.showDone = function (st) {
		this.st = st;
		this.root.classList.add('is-complete');
		this.root.classList.remove('is-running');
		var visit = this.el('visit'), login = this.el('login');
		visit.href = st.peer;
		login.href = st.peer_login;
		this.btn('purge').hidden = !CFG.backup;
		this.btn('rollback').hidden = !CFG.backup;
		this.stage('done');
	};

	SendApp.prototype.after = function (what, confirmText, doneText) {
		var self = this;
		if (!confirm(confirmText)) { return; }
		var status = this.el('afterstatus');
		var buttons = ['purge', 'rollback', 'new'].map(function (a) { return self.btn(a); });
		buttons.forEach(function (b) { b.disabled = true; });
		status.className = 'smg-inline-status';
		status.textContent = 'Working…';
		var step = function () {
			return retry('remote', { what: what }).then(function (r) {
				if (r.label) { status.textContent = r.label + '…'; }
				return r.done ? r : step();
			});
		};
		step().then(function () {
			status.textContent = doneText;
			status.className = 'smg-inline-status is-ok';
			self.btn('purge').hidden = true;
			self.btn('rollback').hidden = true;
		}).catch(function (err) {
			status.textContent = err.message;
			status.className = 'smg-inline-status is-error';
		}).then(function () { buttons.forEach(function (b) { b.disabled = false; }); });
	};

	SendApp.prototype.purge = function () {
		this.after('purge', 'Delete the destination\'s rollback copy? After this the migration cannot be undone.', 'Rollback copy deleted. Disk space freed on the destination.');
	};

	SendApp.prototype.rollback = function () {
		this.after('rollback', 'Put the destination back exactly as it was before this migration?', 'The destination has been rolled back to its previous state.');
	};

	// ------------------------------------------------------------ Receive page

	function ReceiveApp(root) {
		this.root = root;
		this.timer = null;
		this.busy = false;
		this.bind();
		this.poll();
	}

	ReceiveApp.prototype.el = function (name) { return $('[data-bind="' + name + '"]', this.root); };
	ReceiveApp.prototype.set = function (name, t) { var e = this.el(name); if (e) { e.textContent = t; } };

	ReceiveApp.prototype.bind = function () {
		var self = this;
		this.root.addEventListener('click', function (e) {
			var b = e.target.closest('[data-act]');
			if (!b || b.disabled) { return; }
			var act = b.getAttribute('data-act');
			if (self[act]) { e.preventDefault(); self[act](b); }
		});
	};

	ReceiveApp.prototype.showKey = function (active) {
		$('[data-when="active"]', this.root).hidden = !active;
		$('[data-when="inactive"]', this.root).hidden = active;
		this.root.setAttribute('data-active', active ? '1' : '0');
	};

	ReceiveApp.prototype.keyStatus = function (t, kind) {
		var s = this.el('keystatus');
		s.textContent = t;
		s.className = 'smg-inline-status' + (kind ? ' is-' + kind : '');
	};

	ReceiveApp.prototype.generate = function (b) {
		var self = this;
		if (this.root.getAttribute('data-active') === '1' && !confirm('Create a new key? The current key stops working immediately.')) { return; }
		b.disabled = true;
		call('key_generate').then(function (r) {
			$('#smg-recv-key', self.root).value = r.key;
			self.set('expires', r.expires);
			self.showKey(true);
			self.keyStatus('New key ready — copy it to the source site.', 'ok');
		}).catch(function (err) { self.error(err.message); }).then(function () { b.disabled = false; });
	};

	ReceiveApp.prototype.copy = function () {
		var self = this;
		var ta = $('#smg-recv-key', this.root);
		var done = function () { self.keyStatus('Copied to clipboard.', 'ok'); };
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(ta.value).then(done, function () { ta.select(); document.execCommand('copy'); done(); });
		} else {
			ta.select();
			document.execCommand('copy');
			done();
		}
	};

	ReceiveApp.prototype.revoke = function (b) {
		var self = this;
		if (!confirm('Revoke this key? Any migration using it stops immediately.')) { return; }
		b.disabled = true;
		call('key_revoke').then(function () {
			$('#smg-recv-key', self.root).value = '';
			self.showKey(false);
		}).catch(function (err) { self.error(err.message); }).then(function () { b.disabled = false; });
	};

	ReceiveApp.prototype.error = function (msg) {
		var e = this.el('error');
		e.textContent = msg;
		e.hidden = !msg;
	};

	ReceiveApp.prototype.poll = function () {
		var self = this;
		clearTimeout(this.timer);
		if (document.hidden) { this.timer = setTimeout(function () { self.poll(); }, 8000); return; }
		call('recv_status').then(function (s) {
			self.error('');
			self.render(s);
			var delay = s.phase === 'restoring' || s.phase === 'rolling_back' ? 2000 : 4000;
			self.timer = setTimeout(function () { self.poll(); }, delay);
		}).catch(function (err) {
			if (err.fatal) {
				// The site we are looking at was just replaced: our login belongs to the old database.
				self.error('This site has just been replaced by the migrated site, so your old login no longer applies. Log in again with the migrated site\'s username and password.');
				return;
			}
			self.timer = setTimeout(function () { self.poll(); }, 8000);
		});
	};

	ReceiveApp.prototype.render = function (s) {
		var box = this.el('incoming');
		if (!s || s.phase === 'idle') { box.hidden = true; return; }
		box.hidden = false;
		this.set('from', host(s.from));
		var n = (s.steps || []).length || 6;
		var pct = 0, label = '', hint = '';
		switch (s.phase) {
			case 'receiving':
				label = 'Receiving files and database';
				hint = 'Live transfer progress and speed are shown on the sending site. Keep this page open or close it — it does not matter.';
				pct = 0;
				break;
			case 'restoring':
				pct = ((s.index || 0) + (s.step_pct || 0) / 100) / n * 100;
				label = s.label || 'Restoring';
				hint = 'Restoring. Do not change anything on this site until it finishes.';
				break;
			case 'done':
				pct = 100;
				label = 'Migration restored';
				hint = s.can_rollback ? 'Happy with the result? Delete the rollback copy to free disk space. Otherwise roll back to the previous site.' : '';
				break;
			case 'rolling_back':
				label = 'Rolling back';
				pct = 50;
				break;
			case 'rolled_back':
				label = 'Rolled back to the previous site';
				pct = 0;
				break;
			default:
				label = 'Migration ' + s.phase.replace(/_/g, ' ');
		}
		this.set('label', label);
		this.set('pct', s.phase === 'receiving' ? '' : Math.round(pct) + '%');
		this.el('bar').style.width = pct + '%';
		this.el('track').style.width = pct + '%';
		this.set('hint', hint);
		box.classList.toggle('is-complete', s.phase === 'done');
		this.el('after').hidden = !(s.can_rollback && s.phase === 'done') && !this.busy;
		if (s.log && s.log.length) { this.set('log', s.log.join('\n')); }
	};

	ReceiveApp.prototype.act = function (action, confirmText, doneText) {
		var self = this;
		if (!confirm(confirmText)) { return; }
		var status = this.el('afterstatus');
		var btns = $$('[data-bind="after"] button', this.root);
		btns.forEach(function (b) { b.disabled = true; });
		this.busy = true;
		status.className = 'smg-inline-status';
		status.textContent = 'Working…';
		var step = function () {
			return retry(action, {}).then(function (r) {
				if (r.label) { status.textContent = r.label + '…'; }
				return r.done ? r : step();
			});
		};
		step().then(function () {
			status.textContent = doneText;
			status.className = 'smg-inline-status is-ok';
		}).catch(function (err) {
			if (err.fatal && action === 'recv_rollback') {
				// The previous database is back, so this login session belonged to the migrated one.
				status.textContent = 'Rolled back. Reload the page and log in with the previous site\'s credentials.';
				status.className = 'smg-inline-status is-ok';
				return;
			}
			status.textContent = err.message;
			status.className = 'smg-inline-status is-error';
		}).then(function () {
			self.busy = false;
			btns.forEach(function (b) { b.disabled = false; });
			self.poll();
		});
	};

	ReceiveApp.prototype.purge = function () {
		this.act('recv_purge', 'Delete the rollback copy? The migration can no longer be undone afterwards.', 'Rollback copy deleted.');
	};

	ReceiveApp.prototype.rollback = function () {
		this.act('recv_rollback', 'Roll this site back to how it was before the migration? You will need to log in again with the previous site\'s credentials.', 'Rolled back. Reload the page and log in with the previous credentials.');
	};

	// ------------------------------------------------------------ boot

	document.addEventListener('DOMContentLoaded', function () {
		$$('[data-confirm]').forEach(function (b) {
			b.addEventListener('click', function (e) {
				if (!window.confirm(b.getAttribute('data-confirm'))) { e.preventDefault(); }
			});
		});
		var send = document.getElementById('smg-send');
		if (send) { new SendApp(send); }
		var recv = document.getElementById('smg-receive');
		if (recv) { new ReceiveApp(recv); }
	});
})();
