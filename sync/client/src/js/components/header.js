/**
 * Header Component
 */
LibreMail.Components.Header = (function ( Const, Socket, Mustache ) {
'use strict';
// Returns a new instance
return function ( $root ) {
    // Event namespace
    var namespace = '.header';
    // DOM template nodes
    var $header = document.getElementById( 'header' );
    var $status = document.getElementById( 'status' );
    var $accounts = document.getElementById( 'accounts' );
    // Templates
    var tpl = {
        header: $header.innerHTML,
        status: $status.innerHTML,
        accounts: $accounts.innerHTML
    };
    // State for restart command
    var isAsleep = false;
    // State for rendering the parent template
    var rootIsRendered = false;
    // DOM nodes for updating
    var $statusSection;
    var $restartButton;
    var $accountsSection;

    // Parse the templates
    Mustache.parse( tpl.header );
    Mustache.parse( tpl.status );
    Mustache.parse( tpl.accounts );

    /**
     * Triggered from Stats Page
     * @param Object data
     */
    function render ( data ) {
        // Store this for the restart button
        isAsleep = data.asleep;

        if ( rootIsRendered === true ) {
            update( data );
            return;
        }

        $root.innerHTML = Mustache.render(
            tpl.header, {
                asleep: data.asleep,
                uptime: data.uptime,
                account: data.account,
                running: data.running,
                runningTime: function () {
                    return formatTime( this.uptime )
                },
                accounts: Object.keys( data.accounts )
            }, {
                status: tpl.status,
                accounts: tpl.accounts
            });
        rootIsRendered = true;
        $statusSection = $root.querySelector( 'section.status' );
        $accountsSection = $root.querySelector( 'section.accounts' );
        $restartButton = $root.querySelector( 'button#restart' );

        // Attach event handlers to DOM elements.
        $restartButton.onclick = restart;
    }

    function restart () {
        if ( ! isAsleep ) {
            return;
        }

        Socket.send( Const.MSG.RESTART );
    }

    function update ( data ) {
        $statusSection.innerHTML = Mustache.render(
            tpl.status, {
                asleep: data.asleep,
                uptime: data.uptime,
                running: data.running,
                runningTime: function () {
                    return formatTime( this.uptime )
                }
            });
        $accountsSection.innerHTML = Mustache.render(
            tpl.accounts, {
                account: data.account,
                accounts: Object.keys( data.accounts )
            });

        // Mark button disabled if the sync is running
        $restartButton.className = ( ! isAsleep )
            ? 'disabled'
            : '';
    }

    function formatTime ( seconds ) {
        if ( seconds < 60 ) {
            return seconds + "s";
        }
        else if ( seconds < 3600 ) {
            return Math.floor( seconds / 60 ) + "m";
        }
        else if ( seconds < 86400 ) {
            return Math.floor( seconds / 3600 ) + "h"
                + " " + Math.floor( (seconds % 3600) / 60 ) + "m";
        }
        else {
            return Math.floor( seconds / 86400 ) + "d"
                + " " + Math.floor( (seconds / 86400) % 3600 ) + "h";
        }
    }

    return {
        render: render
    };
}}( LibreMail.Const, LibreMail.Socket, Mustache ));