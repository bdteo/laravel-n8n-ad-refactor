#!/usr/bin/env node

/**
 * n8n Workflow Validation Script
 * 
 * This script validates the exported n8n workflow JSON file to ensure:
 * - Valid JSON structure
 * - Required workflow metadata
 * - All nodes are properly configured
 * - Connections are valid
 * - Credentials are referenced correctly
 * - Settings are appropriate for production
 */

const fs = require('fs');
const path = require('path');

// Configuration
const WORKFLOW_FILE = path.join(__dirname, 'workflows', 'ad-script-refactor-workflow.json');
const REQUIRED_NODES = [
    'webhook-trigger',
    'set-variables', 
    'validate-input',
    'ai-agent',
    'check-ai-response',
    'process-success',
    'process-ai-error',
    'process-validation-error',
    'success-callback',
    'error-callback',
    'global-error-handler',
    'global-error-callback'
];

const REQUIRED_CREDENTIALS = [
    'openai-credentials',
    'webhook-auth',
    'laravel-callback-auth'
];

class WorkflowValidator {
    constructor() {
        this.errors = [];
        this.warnings = [];
        this.workflow = null;
    }

    /**
     * Main validation method
     */
    async validate() {
        console.log('🔍 Validating n8n workflow...\n');

        try {
            // Load and parse workflow
            this.loadWorkflow();
            
            // Run validation checks
            this.validateStructure();
            this.validateMetadata();
            this.validateNodes();
            this.validateConnections();
            this.validateCredentials();
            this.validateSettings();
            this.validateErrorHandling();
            
            // Report results
            this.reportResults();
            
        } catch (error) {
            this.errors.push(`Critical error during validation: ${error.message}`);
            this.reportResults();
            process.exit(1);
        }
    }

    /**
     * Load and parse workflow JSON
     */
    loadWorkflow() {
        if (!fs.existsSync(WORKFLOW_FILE)) {
            throw new Error(`Workflow file not found: ${WORKFLOW_FILE}`);
        }

        try {
            const content = fs.readFileSync(WORKFLOW_FILE, 'utf8');
            this.workflow = JSON.parse(content);
            console.log('✅ Workflow JSON loaded and parsed successfully');
        } catch (error) {
            throw new Error(`Failed to parse workflow JSON: ${error.message}`);
        }
    }

    /**
     * Validate basic workflow structure
     */
    validateStructure() {
        console.log('📋 Validating workflow structure...');

        const requiredFields = ['name', 'nodes', 'connections', 'active', 'settings'];
        
        for (const field of requiredFields) {
            if (!this.workflow.hasOwnProperty(field)) {
                this.errors.push(`Missing required field: ${field}`);
            }
        }

        if (!Array.isArray(this.workflow.nodes)) {
            this.errors.push('Nodes field must be an array');
        }

        if (typeof this.workflow.connections !== 'object') {
            this.errors.push('Connections field must be an object');
        }

        console.log('✅ Basic structure validation complete');
    }

    /**
     * Validate workflow metadata
     */
    validateMetadata() {
        console.log('📝 Validating workflow metadata...');

        // Check workflow name
        if (this.workflow.name !== 'Ad Script Refactor Workflow') {
            this.warnings.push(`Unexpected workflow name: ${this.workflow.name}`);
        }

        // Check workflow ID
        if (this.workflow.id !== 'ad-script-refactor') {
            this.warnings.push(`Unexpected workflow ID: ${this.workflow.id}`);
        }

        // Check version
        if (!this.workflow.versionId) {
            this.warnings.push('Workflow version ID not set');
        }

        // Check tags
        if (!this.workflow.tags || !Array.isArray(this.workflow.tags)) {
            this.warnings.push('Workflow tags not properly configured');
        } else {
            const expectedTags = ['laravel-integration', 'ai-processing', 'error-handling'];
            const actualTags = this.workflow.tags.map(tag => tag.id || tag.name);
            
            for (const expectedTag of expectedTags) {
                if (!actualTags.some(tag => tag.toLowerCase().includes(expectedTag))) {
                    this.warnings.push(`Missing expected tag: ${expectedTag}`);
                }
            }
        }

        console.log('✅ Metadata validation complete');
    }

    /**
     * Validate all nodes
     */
    validateNodes() {
        console.log('🔧 Validating workflow nodes...');

        const nodeIds = this.workflow.nodes.map(node => node.id);
        
        // Check for required nodes
        for (const requiredNode of REQUIRED_NODES) {
            if (!nodeIds.includes(requiredNode)) {
                this.errors.push(`Missing required node: ${requiredNode}`);
            }
        }

        // Validate individual nodes
        for (const node of this.workflow.nodes) {
            this.validateNode(node);
        }

        console.log(`✅ Node validation complete (${this.workflow.nodes.length} nodes checked)`);
    }

    /**
     * Validate individual node
     */
    validateNode(node) {
        // Check required node fields
        const requiredFields = ['id', 'name', 'type', 'position'];
        for (const field of requiredFields) {
            if (!node.hasOwnProperty(field)) {
                this.errors.push(`Node ${node.id || 'unknown'} missing field: ${field}`);
            }
        }

        // Validate specific node types
        switch (node.id) {
            case 'webhook-trigger':
                this.validateWebhookNode(node);
                break;
            case 'ai-agent':
                this.validateAINode(node);
                break;
            case 'success-callback':
            case 'error-callback':
            case 'global-error-callback':
                this.validateCallbackNode(node);
                break;
        }
    }

    /**
     * Validate webhook trigger node
     */
    validateWebhookNode(node) {
        if (node.type !== 'n8n-nodes-base.webhook') {
            this.errors.push('Webhook trigger has incorrect type');
        }

        if (!node.parameters || node.parameters.path !== 'ad-script-refactor-openrouter') {
            this.errors.push('Webhook trigger path not configured correctly');
        }

        if (!node.parameters || node.parameters.httpMethod !== 'POST') {
            this.errors.push('Webhook trigger method should be POST');
        }

        if (!node.credentials || !node.credentials.httpHeaderAuth) {
            this.errors.push('Webhook trigger missing authentication configuration');
        }
    }

    /**
     * Validate AI agent node
     */
    validateAINode(node) {
        if (node.type !== '@n8n/n8n-nodes-langchain.openAi') {
            this.errors.push('AI agent has incorrect type');
        }

        if (!node.parameters || node.parameters.model !== 'gpt-4o') {
            this.warnings.push('AI agent not using expected model (gpt-4o)');
        }

        if (!node.parameters || !node.parameters.options || node.parameters.options.temperature !== 0.3) {
            this.warnings.push('AI agent temperature not set to recommended value (0.3)');
        }

        if (!node.parameters || !node.parameters.options || node.parameters.options.maxTokens !== 2000) {
            this.warnings.push('AI agent max tokens not set to recommended value (2000)');
        }

        if (!node.credentials || !node.credentials.openAiApi) {
            this.errors.push('AI agent missing OpenAI credentials');
        }
    }

    /**
     * Validate callback nodes
     */
    validateCallbackNode(node) {
        if (node.type !== 'n8n-nodes-base.httpRequest') {
            this.errors.push(`Callback node ${node.id} has incorrect type`);
        }

        if (!node.parameters || !node.parameters.url || 
            !node.parameters.url.includes('/api/ad-scripts/')) {
            this.errors.push(`Callback node ${node.id} URL not configured correctly`);
        }

        if (!node.parameters || !node.parameters.options || !node.parameters.options.retry) {
            this.warnings.push(`Callback node ${node.id} missing retry configuration`);
        }

        if (!node.credentials || !node.credentials.httpHeaderAuth) {
            this.errors.push(`Callback node ${node.id} missing authentication`);
        }
    }

    /**
     * Validate node connections
     */
    validateConnections() {
        console.log('🔗 Validating node connections...');

        const nodeNames = this.workflow.nodes.map(node => node.name);
        
        // Check that all connection references exist
        for (const [sourceNodeName, connections] of Object.entries(this.workflow.connections)) {
            if (!nodeNames.includes(sourceNodeName)) {
                this.errors.push(`Connection references non-existent source node: ${sourceNodeName}`);
                continue;
            }

            if (connections.main && Array.isArray(connections.main)) {
                for (const connectionGroup of connections.main) {
                    if (Array.isArray(connectionGroup)) {
                        for (const connection of connectionGroup) {
                            if (!nodeNames.includes(connection.node)) {
                                this.errors.push(`Connection references non-existent target node: ${connection.node}`);
                            }
                        }
                    }
                }
            }
        }

        // Validate critical path connections
        this.validateCriticalPath();

        console.log('✅ Connection validation complete');
    }

    /**
     * Validate critical workflow path
     */
    validateCriticalPath() {
        const criticalPath = [
            'Webhook Trigger',
            'Set Variables', 
            'Validate Input',
            'AI Agent (GPT-4o)',
            'Check AI Response',
            'Process Success',
            'Success Callback'
        ];

        // This is a simplified check - in a real scenario you'd trace the actual connections
        for (const nodeName of criticalPath) {
            const node = this.workflow.nodes.find(n => n.name === nodeName);
            if (!node) {
                this.errors.push(`Critical path node missing: ${nodeName}`);
            }
        }
    }

    /**
     * Validate credential references
     */
    validateCredentials() {
        console.log('🔐 Validating credential references...');

        const credentialRefs = new Set();
        
        // Collect all credential references
        for (const node of this.workflow.nodes) {
            if (node.credentials) {
                for (const [credType, credConfig] of Object.entries(node.credentials)) {
                    if (credConfig.id) {
                        credentialRefs.add(credConfig.id);
                    }
                }
            }
        }

        // Check for required credentials
        for (const requiredCred of REQUIRED_CREDENTIALS) {
            if (!credentialRefs.has(requiredCred)) {
                this.warnings.push(`Required credential not referenced: ${requiredCred}`);
            }
        }

        console.log(`✅ Credential validation complete (${credentialRefs.size} credentials referenced)`);
    }

    /**
     * Validate workflow settings
     */
    validateSettings() {
        console.log('⚙️ Validating workflow settings...');

        if (!this.workflow.settings) {
            this.warnings.push('Workflow settings not configured');
            return;
        }

        // Check timezone
        if (this.workflow.settings.timezone !== 'UTC') {
            this.warnings.push('Workflow timezone should be UTC for consistency');
        }

        // Check execution settings
        if (!this.workflow.settings.saveManualExecutions) {
            this.warnings.push('Manual execution saving disabled - may impact debugging');
        }

        // Check error workflow
        if (!this.workflow.settings.errorWorkflow || !this.workflow.settings.errorWorkflow.enabled) {
            this.warnings.push('Error workflow not enabled');
        }

        console.log('✅ Settings validation complete');
    }

    /**
     * Validate error handling configuration
     */
    validateErrorHandling() {
        console.log('🚨 Validating error handling...');

        const errorTriggers = this.workflow.nodes.filter(node => 
            node.type === 'n8n-nodes-base.errorTrigger'
        );

        if (errorTriggers.length === 0) {
            this.errors.push('No error trigger nodes found');
        } else if (errorTriggers.length < 4) {
            this.warnings.push(`Only ${errorTriggers.length} error triggers found, expected 4`);
        }

        // Check for global error handler
        const globalErrorHandler = this.workflow.nodes.find(node => 
            node.id === 'global-error-handler'
        );

        if (!globalErrorHandler) {
            this.errors.push('Global error handler node missing');
        }

        console.log('✅ Error handling validation complete');
    }

    /**
     * Report validation results
     */
    reportResults() {
        console.log('\n📊 Validation Results');
        console.log('='.repeat(50));

        if (this.errors.length === 0 && this.warnings.length === 0) {
            console.log('🎉 Workflow validation passed with no issues!');
            console.log('\n✅ The workflow is ready for import and deployment.');
        } else {
            if (this.errors.length > 0) {
                console.log(`\n❌ Errors (${this.errors.length}):`);
                this.errors.forEach((error, index) => {
                    console.log(`   ${index + 1}. ${error}`);
                });
            }

            if (this.warnings.length > 0) {
                console.log(`\n⚠️  Warnings (${this.warnings.length}):`);
                this.warnings.forEach((warning, index) => {
                    console.log(`   ${index + 1}. ${warning}`);
                });
            }

            if (this.errors.length > 0) {
                console.log('\n❌ Workflow validation failed. Please fix errors before deployment.');
                process.exit(1);
            } else {
                console.log('\n⚠️  Workflow validation passed with warnings. Review warnings before deployment.');
            }
        }

        // Summary
        console.log('\n📈 Summary:');
        console.log(`   Nodes: ${this.workflow?.nodes?.length || 0}`);
        console.log(`   Connections: ${Object.keys(this.workflow?.connections || {}).length}`);
        console.log(`   Version: ${this.workflow?.versionId || 'Not set'}`);
        console.log(`   Active: ${this.workflow?.active ? 'Yes' : 'No'}`);
    }
}

// Run validation if called directly
if (require.main === module) {
    const validator = new WorkflowValidator();
    validator.validate().catch(error => {
        console.error('❌ Validation failed:', error.message);
        process.exit(1);
    });
}

module.exports = WorkflowValidator; 