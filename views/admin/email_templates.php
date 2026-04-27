<?php
/**
 * Email Templates Management for Admin
 * 
 * UI component for managing admin invite and marketing email templates
 * 
 * @package STAMGAST
 * @subpackage Views\Admin
 */
?>

<div class="card">
    <div class="card-header">
        <h3>Email Template Management</h3>
        <p>Manage email templates for your tenant</p>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-12">
                <div class="alert alert-info">
                    <strong>Note:</strong> You can manage admin invite, guest confirmation, and marketing templates.
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-12">
                <ul class="nav nav-tabs" id="template-tabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="admin-invite-tab" data-toggle="tab" href="#admin-invite" role="tab">
                            Admin Invite
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="guest-confirmation-tab" data-toggle="tab" href="#guest-confirmation" role="tab">
                            Guest Confirmation
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="marketing-tab" data-toggle="tab" href="#marketing" role="tab">
                            Marketing
                        </a>
                    </li>
                </ul>
                <div class="tab-content" id="template-tab-content">
                    <div class="tab-pane fade show active" id="admin-invite" role="tabpanel">
                        <div class="table-responsive mt-3">
                            <table class="table table-striped table-hover" id="admin-invite-table">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Last Updated</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Admin invite templates will be populated by JavaScript -->
                                </tbody>
                            </table>
                        </div>
                        <button class="btn btn-primary mt-2" onclick="openTemplateEditor('admin_invite')">
                            <i class="fas fa-plus"></i> Add Template
                        </button>
                    </div>
                    <div class="tab-pane fade" id="guest-confirmation" role="tabpanel">
                        <div class="table-responsive mt-3">
                            <table class="table table-striped table-hover" id="guest-confirmation-table">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Last Updated</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Guest confirmation templates will be populated by JavaScript -->
                                </tbody>
                            </table>
                        </div>
                        <button class="btn btn-primary mt-2" onclick="openTemplateEditor('guest_confirmation')">
                            <i class="fas fa-plus"></i> Add Template
                        </button>
                    </div>
                    <div class="tab-pane fade" id="marketing" role="tabpanel">
                        <div class="table-responsive mt-3">
                            <table class="table table-striped table-hover" id="marketing-table">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Last Updated</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Marketing templates will be populated by JavaScript -->
                                </tbody>
                            </table>
                        </div>
                        <button class="btn btn-primary mt-2" onclick="openTemplateEditor('marketing')">
                            <i class="fas fa-plus"></i> Add Template
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Template Editor Modal -->
<div class="modal fade" id="adminTemplateModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Email Template Editor</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="admin-template-form">
                    <input type="hidden" id="admin-template-id">
                    <input type="hidden" id="admin-template-type">
                    
                    <div class="form-group">
                        <label for="admin-template-subject">Subject *</label>
                        <input type="text" class="form-control" id="admin-template-subject" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="admin-template-language">Language</label>
                        <select class="form-control" id="admin-template-language">
                            <option value="nl" selected>Dutch</option>
                            <option value="en">English</option>
                            <option value="fr">French</option>
                            <option value="de">German</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="admin-template-content">Content *</label>
                        <textarea class="form-control" id="admin-template-content" rows="15" required></textarea>
                        <small class="form-text text-muted">
                            Available placeholders: {{user_name}}, {{tenant_name}}, {{invitation_link}}, {{verification_code}}, {{campaign_name}}, {{campaign_message}}
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="admin-template-text-content">Plain Text Content</label>
                        <textarea class="form-control" id="admin-template-text-content" rows="5"></textarea>
                        <small class="form-text text-muted">
                            Plain text version for email clients that don't support HTML
                        </small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="admin-save-template-btn">Save Template</button>
            </div>
        </div>
    </div>
</div>

<script>
// Admin Email Template Management
$(document).ready(function() {
    // Load templates on page load
    loadAdminTemplates();
    
    // Bind save template button
    $('#admin-save-template-btn').click(function() {
        saveAdminTemplate();
    });
    
    // Load templates function
    function loadAdminTemplates() {
        $.ajax({
            url: '<?= BASE_URL ?>/api/email/templates',
            method: 'GET',
            dataType: 'json',
            success: function(templates) {
                // Process admin invite templates
                var adminInviteTemplates = templates.filter(function(template) {
                    return template.type === 'admin_invite';
                });
                
                var adminInviteTbody = $('#admin-invite-table tbody');
                adminInviteTbody.empty();
                
                if (adminInviteTemplates.length === 0) {
                    adminInviteTbody.append('<tr><td colspan="3" class="text-center">No templates found</td></tr>');
                } else {
                    adminInviteTemplates.forEach(function(template) {
                        var row = '<tr>' +
                            '<td>' + template.subject + '</td>' +
                            '<td>' + new Date(template.updated_at).toLocaleString() + '</td>' +
                            '<td>' +
                                '<button class="btn btn-sm btn-info edit-admin-template" data-id="' + template.id + '">' +
                                    '<i class="fas fa-edit"></i> Edit' +
                                '</button> ' +
                                '<button class="btn btn-sm btn-danger delete-admin-template" data-id="' + template.id + '">' +
                                    '<i class="fas fa-trash"></i> Delete' +
                                '</button>' +
                            '</td>' +
                        '</tr>';
                        adminInviteTbody.append(row);
                    });
                }
                
                // Process guest confirmation templates
                var guestConfirmationTemplates = templates.filter(function(template) {
                    return template.type === 'guest_confirmation';
                });
                
                var guestConfirmationTbody = $('#guest-confirmation-table tbody');
                guestConfirmationTbody.empty();
                
                if (guestConfirmationTemplates.length === 0) {
                    guestConfirmationTbody.append('<tr><td colspan="3" class="text-center">No templates found</td></tr>');
                } else {
                    guestConfirmationTemplates.forEach(function(template) {
                        var row = '<tr>' +
                            '<td>' + template.subject + '</td>' +
                            '<td>' + new Date(template.updated_at).toLocaleString() + '</td>' +
                            '<td>' +
                                '<button class="btn btn-sm btn-info edit-admin-template" data-id="' + template.id + '">' +
                                    '<i class="fas fa-edit"></i> Edit' +
                                '</button> ' +
                                '<button class="btn btn-sm btn-danger delete-admin-template" data-id="' + template.id + '">' +
                                    '<i class="fas fa-trash"></i> Delete' +
                                '</button>' +
                            '</td>' +
                        '</tr>';
                        guestConfirmationTbody.append(row);
                    });
                }
                
                // Process marketing templates
                var marketingTemplates = templates.filter(function(template) {
                    return template.type === 'marketing';
                });
                
                var marketingTbody = $('#marketing-table tbody');
                marketingTbody.empty();
                
                if (marketingTemplates.length === 0) {
                    marketingTbody.append('<tr><td colspan="3" class="text-center">No templates found</td></tr>');
                } else {
                    marketingTemplates.forEach(function(template) {
                        var row = '<tr>' +
                            '<td>' + template.subject + '</td>' +
                            '<td>' + new Date(template.updated_at).toLocaleString() + '</td>' +
                            '<td>' +
                                '<button class="btn btn-sm btn-info edit-admin-template" data-id="' + template.id + '">' +
                                    '<i class="fas fa-edit"></i> Edit' +
                                '</button> ' +
                                '<button class="btn btn-sm btn-danger delete-admin-template" data-id="' + template.id + '">' +
                                    '<i class="fas fa-trash"></i> Delete' +
                                '</button>' +
                            '</td>' +
                        '</tr>';
                        marketingTbody.append(row);
                    });
                }
                
                // Bind edit button events
                $('.edit-admin-template').click(function() {
                    var id = $(this).data('id');
                    editAdminTemplate(id);
                });
                
                // Bind delete button events
                $('.delete-admin-template').click(function() {
                    var id = $(this).data('id');
                    deleteAdminTemplate(id);
                });
            },
            error: function(xhr, status, error) {
                console.error('Error loading templates:', error);
            }
        });
    }
    
    // Edit template function
    function editAdminTemplate(id) {
        $.ajax({
            url: '<?= BASE_URL ?>/api/email/templates?id=' + id,
            method: 'GET',
            dataType: 'json',
            success: function(template) {
                $('#admin-template-id').val(template.id);
                $('#admin-template-type').val(template.type);
                $('#admin-template-subject').val(template.subject);
                $('#admin-template-content').val(template.content);
                $('#admin-template-text-content').val(template.text_content || '');
                $('#admin-template-language').val(template.language_code);
                
                $('#adminTemplateModal').modal('show');
            },
            error: function(xhr, status, error) {
                alert('Error loading template: ' + xhr.responseJSON?.error || error);
            }
        });
    }
    
    // Delete template function
    function deleteAdminTemplate(id) {
        if (!confirm('Are you sure you want to delete this template?')) {
            return;
        }
        
        $.ajax({
            url: '<?= BASE_URL ?>/api/email/templates',
            method: 'DELETE',
            contentType: 'application/json',
            data: JSON.stringify({id: id}),
            success: function(response) {
                alert('Template deleted successfully');
                loadAdminTemplates();
            },
            error: function(xhr, status, error) {
                alert('Error deleting template: ' + xhr.responseJSON?.error || error);
            }
        });
    }
    
    // Save template function
    function saveAdminTemplate() {
        var data = {
            id: $('#admin-template-id').val(),
            type: $('#admin-template-type').val(),
            subject: $('#admin-template-subject').val(),
            content: $('#admin-template-content').val(),
            text_content: $('#admin-template-text-content').val(),
            language_code: $('#admin-template-language').val()
        };
        
        var method = data.id ? 'PUT' : 'POST';
        var url = '<?= BASE_URL ?>/api/email/templates';
        
        $.ajax({
            url: url,
            method: method,
            contentType: 'application/json',
            data: JSON.stringify(data),
            success: function(response) {
                alert('Template saved successfully');
                $('#adminTemplateModal').modal('hide');
                loadAdminTemplates();
            },
            error: function(xhr, status, error) {
                alert('Error saving template: ' + xhr.responseJSON?.error || error);
            }
        });
    }
    
    // Open template editor function
    function openTemplateEditor(type) {
        // Clear form
        $('#admin-template-id').val('');
        $('#admin-template-type').val(type);
        $('#admin-template-subject').val('');
        $('#admin-template-content').val('');
        $('#admin-template-text-content').val('');
        $('#admin-template-language').val('nl');
        
        // Show modal
        $('#adminTemplateModal').modal('show');
    }
});
</script>