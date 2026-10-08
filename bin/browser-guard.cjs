#!/usr/bin/env node
'use strict';

/**
 * Wraps spatie/browsershot's bin/browser.cjs so Chromium can never outlive the
 * request that started it.
 *
 * Puppeteer launches Chromium detached (in its own process group). When the
 * PHP side gives up (Symfony Process timeout sends SIGKILL to node, php-fpm
 * kills a worker, ...), node dies but Chromium keeps running and holds
 * ~400 MB until the container is restarted. This guard:
 *
 *  - kills Chromium's whole process group on every exit path,
 *  - enforces a hard deadline slightly below the PHP-side process timeout,
 *    so node gets to clean up before PHP's SIGKILL arrives,
 *  - aborts as soon as its parent (the PHP worker) disappears,
 *  - handles SIGTERM / SIGINT / SIGHUP.
 *
 * Environment (set by App\Libraries\BrowsershotGenerator):
 *   BROWSERSHOT_DEADLINE_MS  hard limit for the whole render (0 = none)
 *   BROWSERSHOT_WORKDIR      per-request temp dir, removed on abort
 *   BROWSERSHOT_SPATIE_BIN   override path to spatie's browser.cjs
 *
 * Exit codes: 124 deadline exceeded, 125 parent died, 128+n signal n.
 * Anything else comes from browser.cjs itself.
 */

const fs = require('fs');
const path = require('path');

const EXIT_DEADLINE = 124;
const EXIT_ORPHANED = 125;
const PARENT_POLL_MS = 250;

const spatieBin = process.env.BROWSERSHOT_SPATIE_BIN
    || path.join(__dirname, '..', 'vendor', 'spatie', 'browsershot', 'bin', 'browser.cjs');

/** Browsers launched during this render. */
const browsers = new Set();

function killBrowsers() {
    for (const browser of browsers) {
        let child = null;
        try {
            child = browser.process();
        } catch {
            // connect()ed browsers have no local process.
        }

        if (!child || !child.pid || child.exitCode !== null || child.signalCode !== null) {
            continue;
        }

        try {
            // Negative pid = the whole process group (renderers, utilities, ...).
            process.kill(-child.pid, 'SIGKILL');
        } catch {
            try {
                child.kill('SIGKILL');
            } catch {
                // Already gone.
            }
        }
    }
}

function removeWorkDir() {
    const dir = process.env.BROWSERSHOT_WORKDIR;

    // Only ever delete directories created by BrowsershotGenerator.
    if (!dir || !path.basename(dir).startsWith('browsershot-')) {
        return;
    }

    try {
        fs.rmSync(dir, { recursive: true, force: true });
    } catch {
        // Best effort; the container reaper removes leftovers.
    }
}

let aborting = false;

function abort(code, message) {
    if (aborting) {
        return;
    }
    aborting = true;

    killBrowsers();
    removeWorkDir();

    // browser.cjs reports through stdout JSON; keep the same shape.
    try {
        process.stderr.write(message + '\n');
        process.stdout.write(JSON.stringify({ exception: message }) + '\n');
    } catch {
        // stdout may already be closed when the parent is gone.
    }

    process.exit(code);
}

// Last line of defence: whatever path leads to exit, Chromium goes with us.
process.on('exit', killBrowsers);

for (const [signal, number] of [['SIGTERM', 15], ['SIGINT', 2], ['SIGHUP', 1]]) {
    process.on(signal, () => abort(128 + number, `Received ${signal}, render aborted`));
}

const deadline = Number(process.env.BROWSERSHOT_DEADLINE_MS) || 0;
if (deadline > 0) {
    setTimeout(
        () => abort(EXIT_DEADLINE, `Render exceeded the deadline of ${deadline}ms`),
        deadline,
    ).unref();
}

const parentPid = process.ppid;
setInterval(() => {
    if (process.ppid !== parentPid) {
        abort(EXIT_ORPHANED, 'Parent process exited, render aborted');
    }
}, PARENT_POLL_MS).unref();

// Hand browser.cjs a puppeteer that records every browser it creates.
const puppeteer = require('puppeteer');
const trackedPuppeteer = new Proxy(puppeteer, {
    get(target, prop, receiver) {
        if (prop === 'launch' || prop === 'connect') {
            return async (...args) => {
                const browser = await target[prop](...args);
                browsers.add(browser);

                // The deadline may have fired while launch() was in flight.
                if (aborting) {
                    killBrowsers();
                }

                return browser;
            };
        }

        return Reflect.get(target, prop, receiver);
    },
});

// browser.cjs reads the request from process.argv[2], which is unchanged here.
require(spatieBin).callChrome(trackedPuppeteer);
