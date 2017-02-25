"use strict";
var page = require('webpage').create(),
    system = require('system'),
    address, output, size;

if (system.args.length < 3 || system.args.length > 7) {
    console.log('Usage: rasterize.js URL filename cookie1');
    phantom.exit(1);
} else {
    address = system.args[1];
    output = system.args[2];
    page.viewportSize = { width: 768, height: 1024 };

    page.settings.userAgent = '   ';
    
    phantom.addCookie(
        {'domain':'.en.cx', 'name':'GUID', 'value':system.args[3]}
    );
    phantom.addCookie(
        {'domain':'.en.cx', 'name':'stoken', 'value':system.args[4]}
    );
    phantom.addCookie(
        {'domain':'.en.cx', 'name':'lang', 'value':'ru'}
    );
    phantom.addCookie(
        {'domain':'.en.cx', 'name':'Domain', 'value':system.args[5]}
    );
    phantom.addCookie(
        {'domain':'.en.cx', 'name':'atoken', 'value':system.args[6]}
    );
    
    page.open(address, function (status) {
        if (status !== 'success') {
            console.log('Unable to load the address!');
            phantom.exit(1);
        } else {
            window.setTimeout(function () {
                page.render(output);
                phantom.exit();
            }, 200);
        }
    });
}