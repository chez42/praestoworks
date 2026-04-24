{*+**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.1
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 ************************************************************************************}
{strip}
    <style>
        /* Force container visibility */
        #modnavigator, .module-nav, .mod-switcher-container, .mmModulesMenu {
            overflow: visible !important;
        }
        
        /* Force dropdown appearance */
        .mailManagerDropDown {
            position: absolute !important;
            z-index: 999999 !important;
            background-color: #ffffff !important;
            border: 1px solid #ccc !important;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2) !important;
            min-width: 250px !important;
            padding: 5px 0 !important;
        }

        .mailManagerDropDown li {
            background-color: #ffffff !important;
        }
        
        .mailManagerDropDown li a {
            color: #333333 !important;
            padding: 8px 15px !important;
            display: block !important;
        }

        .mailManagerDropDown li a:hover {
            background-color: #f5f5f5 !important;
        }
    </style>
    {assign var="CURRENT_ACCOUNT_ID" value=$MAILBOX->account_id()}
    <div id="modules-menu" class="modules-menu mmModulesMenu" style="width: 100%;">
        <div style="padding: 6px 12px; display: flex; align-items: center; justify-content: space-between;">
            <div class="mailAccountSwitcherTrigger" style="cursor: pointer; max-width: 60%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; color: #4F6EF7;" title="{$MAILBOX->username()}">
                <i class="fa fa-envelope"></i>&nbsp; <span style="font-weight: 600; font-size: 13px;">{$MAILBOX->username()}</span>
            </div>
            
            <span>
                <span class="cursorPointer mailAccountSwitcherTrigger" title="Switch Mailbox" style="margin-right: 6px; color: #777;">
                    <i class="fa fa-exchange"></i>
                </span>
                <span class="cursorPointer mailbox_refresh" title="{vtranslate('LBL_Refresh', $MODULE)}" style="margin-right: 6px; color: #777;">
                    <i class="fa fa-refresh"></i>
                </span>
                <span class="cursorPointer mailbox_setting" title="{vtranslate('JSLBL_Settings', $MODULE)}" data-boxid="{$CURRENT_ACCOUNT_ID}" style="color: #777;">
                    <i class="fa fa-cog"></i>
                </span>
            </span>
        </div>

        <div id="mailboxSwitcherTemplate" class="hide">
            <div class="modal-dialog modal-md">
                <div class="modal-content">
                    <div class="modal-header">
                        <div class="clearfix">
                            <div class="pull-right " >
                                <button type="button" class="close" aria-label="Close" data-dismiss="modal">
                                    <span aria-hidden="true" class='fa fa-close'></span>
                                </button>
                            </div>
                            <h4 class="pull-left">Switch Mailbox</h4>
                        </div>
                    </div>
                    <div class="modal-body" style="padding: 15px 20px;">
                        <div class="list-group" style="margin-bottom: 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.05);">
                            {foreach item=MAILMODEL from=$MAILMODELS}
                                <a href="#" class="list-group-item switch-mailbox-item {if $CURRENT_ACCOUNT_ID eq $MAILMODEL['account_id']}active{/if}" data-accountid="{$MAILMODEL['account_id']}" style="{if $CURRENT_ACCOUNT_ID eq $MAILMODEL['account_id']}background-color: #4F6EF7; border-color: #4F6EF7;{/if}">
                                    <h5 style="margin: 0; font-weight: 600;">
                                        <i class="fa {if $CURRENT_ACCOUNT_ID eq $MAILMODEL['account_id']}fa-check-circle{else}fa-envelope-o{/if}"></i> &nbsp; {$MAILMODEL['account_name']}
                                    </h5>
                                    <small style="{if $CURRENT_ACCOUNT_ID eq $MAILMODEL['account_id']}color: #fff; opacity: 0.8;{else}color: #777;{/if}"><i class="fa fa-server"></i> {$MAILMODEL['server']}</small>
                                </a>
                            {/foreach}
                        </div>
                        <button class="btn btn-success btn-block add-new-mailbox-btn"><i class="fa fa-plus"></i> Add New Mailbox</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        {literal}
        jQuery(document).ready(function() {
            jQuery('.mailAccountSwitcherTrigger').on('click', function() {
                var modalContent = jQuery('#mailboxSwitcherTemplate').html();
                app.helper.showModal(modalContent, {
                    cb: function(modalContainer) {
                        modalContainer.find('.switch-mailbox-item').on('click', function(e) {
                            e.preventDefault();
                            var accountId = jQuery(this).data('accountid');
                            if (accountId) {
                                app.helper.hideModal();
                                var mmInstance = new MailManager_List_Js();
                                mmInstance.loadFolders('', accountId);
                                // Reload page to update header and other components
                                window.location.href = 'index.php?module=MailManager&view=List&account_id=' + accountId;
                            }
                        });

                        modalContainer.find('.add-new-mailbox-btn').on('click', function() {
                            var params = {
                                'module': 'MailManager',
                                'view': 'Index',
                                '_operation': 'settings',
                                '_operationarg': 'edit',
                                'account_id': '',
                                'create': 'new',
                                'mode': 'edit'
                            };
                            app.helper.hideModal();
                            var popupInstance = Vtiger_Popup_Js.getInstance();
                            popupInstance.showPopup(params, '', function(data) {
                                var mmInstance = new MailManager_List_Js();
                                mmInstance.handleSettingsEvents(data);
                                mmInstance.registerDeleteMailboxEvent(data);
                                mmInstance.registerSaveMailboxEvent(data);
                            });
                        });
                    }
                });
            });
        });
        {/literal}
        </script>

        <div id="mail_compose" class="cursorPointer">
            <i class="fa fa-pencil-square-o"></i>&nbsp;{vtranslate('LBL_Compose', $MODULE)}
        </div>
        <div id='folders_list'></div>
    </div>
{/strip}