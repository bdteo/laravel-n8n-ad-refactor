# n8n Workflow Version Control Guide

## Overview

This guide outlines best practices for managing n8n workflow versions, exports, imports, and integration with Git version control for the Ad Script Refactor Workflow.

## Workflow File Structure

```
n8n/
├── workflows/
│   └── ad-script-refactor-workflow.json    # Main workflow export
├── WORKFLOW_DOCUMENTATION.md               # Comprehensive workflow docs
├── README.md                               # Setup and usage guide
├── ERROR_HANDLING_GUIDE.md                 # Error handling documentation
├── AI_AGENT_CONFIGURATION.md               # AI agent configuration
├── WORKFLOW_VERSION_CONTROL.md             # This file
├── test-error-handling.js                  # Error handling tests
└── test-ai-agent.js                        # AI agent tests
```

## Version Control Strategy

### 1. Workflow Versioning

The workflow uses a semantic versioning approach:

- **Major Version**: Breaking changes to workflow structure or API
- **Minor Version**: New features or significant enhancements
- **Patch Version**: Bug fixes and minor improvements

Current version tracking:
- **Workflow Version**: Stored in `versionId` field in JSON
- **Git Tags**: Tagged releases for major versions
- **Documentation**: Version history in WORKFLOW_DOCUMENTATION.md

### 2. Export Process

#### Manual Export (Recommended for Changes)

1. **Access n8n Interface**:
   ```bash
   # Ensure n8n is running
   docker-compose up -d n8n
   
   # Access interface
   open http://localhost:5678
   ```

2. **Export Workflow**:
   - Open the "Ad Script Refactor Workflow"
   - Click the "..." menu in the top-right
   - Select "Download"
   - Save as `ad-script-refactor-workflow.json`

3. **Replace Existing File**:
   ```bash
   # Navigate to project root
   cd /path/to/laravel-n8n-ad-refactor
   
   # Replace the workflow file
   mv ~/Downloads/ad-script-refactor-workflow.json n8n/workflows/
   ```

#### Automated Export (Future Enhancement)

For production environments, consider implementing automated exports:

```bash
#!/bin/bash
# export-workflow.sh
# Automated workflow export script

N8N_API_URL="http://localhost:5678/api/v1"
WORKFLOW_ID="ad-script-refactor"
OUTPUT_FILE="n8n/workflows/ad-script-refactor-workflow.json"

# Export workflow via API
curl -X GET "${N8N_API_URL}/workflows/${WORKFLOW_ID}" \
  -H "Content-Type: application/json" \
  -o "${OUTPUT_FILE}"

echo "Workflow exported to ${OUTPUT_FILE}"
```

### 3. Import Process

#### Fresh Installation

1. **Start n8n**:
   ```bash
   docker-compose up -d n8n
   ```

2. **Access Interface**:
   ```bash
   open http://localhost:5678
   ```

3. **Import Workflow**:
   - Navigate to "Workflows"
   - Click "Import from File"
   - Select `n8n/workflows/ad-script-refactor-workflow.json`
   - Click "Import"

4. **Configure Credentials** (see Configuration section below)

5. **Activate Workflow**:
   - Open the imported workflow
   - Toggle "Active" in the top-right corner

#### Update Existing Workflow

1. **Backup Current Workflow** (if needed):
   - Export current version before importing new one
   - Store backup with timestamp

2. **Import Updated Workflow**:
   - Follow fresh installation steps
   - n8n will update the existing workflow

3. **Verify Configuration**:
   - Check all credentials are still configured
   - Verify environment variables
   - Test webhook endpoint

## Configuration Management

### Required Credentials

After importing the workflow, configure these credentials:

#### 1. OpenAI API Credentials
```
Name: OpenAI API
Type: OpenAI API
API Key: [Your OpenAI API Key]
```

#### 2. Laravel Webhook Authentication
```
Name: Laravel Webhook Auth
Type: Header Auth
Header Name: X-Webhook-Secret
Header Value: [Value from N8N_WEBHOOK_SECRET env var]
```

#### 3. Laravel Callback Authentication
```
Name: Laravel Callback Auth
Type: Header Auth
Header Name: X-Webhook-Secret
Header Value: [Value from LARAVEL_CALLBACK_SECRET env var]
```

### Environment Variables

Ensure these are set in your n8n environment:

```bash
# In docker-compose.yml or .env file
LARAVEL_APP_URL=http://app:8000
OPENAI_API_KEY=your-openai-api-key-here
```

## Git Integration

### 1. Tracking Changes

The workflow JSON file is tracked in Git:

```bash
# Add workflow changes
git add n8n/workflows/ad-script-refactor-workflow.json

# Commit with descriptive message
git commit -m "feat(n8n): update workflow with enhanced error handling

- Added global error handler for unexpected failures
- Improved AI response validation
- Enhanced callback retry logic
- Updated workflow to version 2"
```

### 2. Branching Strategy

For workflow changes:

```bash
# Create feature branch for workflow changes
git checkout -b feature/n8n-workflow-improvements

# Make changes in n8n interface
# Export updated workflow
# Replace file in repository

# Commit changes
git add n8n/workflows/ad-script-refactor-workflow.json
git add n8n/WORKFLOW_DOCUMENTATION.md  # Update docs if needed
git commit -m "feat(n8n): implement workflow improvements"

# Push and create PR
git push origin feature/n8n-workflow-improvements
```

### 3. Release Process

For major workflow releases:

```bash
# Create release branch
git checkout -b release/workflow-v2.0

# Finalize workflow and documentation
# Export final workflow version
# Update version documentation

# Commit release
git commit -m "release(n8n): workflow version 2.0"

# Merge to main
git checkout main
git merge release/workflow-v2.0

# Tag release
git tag -a n8n-workflow-v2.0 -m "n8n Workflow Version 2.0

- Enhanced error handling
- Improved AI processing
- Comprehensive callback system
- Production-ready monitoring"

# Push tags
git push origin --tags
```

## Backup and Recovery

### 1. Regular Backups

Create automated backups of workflow configurations:

```bash
#!/bin/bash
# backup-workflow.sh

BACKUP_DIR="backups/n8n-workflows"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/ad-script-refactor-workflow_${TIMESTAMP}.json"

# Create backup directory
mkdir -p "${BACKUP_DIR}"

# Copy current workflow
cp n8n/workflows/ad-script-refactor-workflow.json "${BACKUP_FILE}"

echo "Workflow backed up to ${BACKUP_FILE}"

# Keep only last 10 backups
ls -t "${BACKUP_DIR}"/ad-script-refactor-workflow_*.json | tail -n +11 | xargs rm -f
```

### 2. Recovery Process

To restore from backup:

```bash
# List available backups
ls -la backups/n8n-workflows/

# Restore specific backup
cp backups/n8n-workflows/ad-script-refactor-workflow_20240101_120000.json \
   n8n/workflows/ad-script-refactor-workflow.json

# Import into n8n (follow import process above)
```

## Testing After Changes

### 1. Automated Testing

Run test suites after workflow changes:

```bash
# Test error handling
node n8n/test-error-handling.js

# Test AI agent configuration
node n8n/test-ai-agent.js
```

### 2. Integration Testing

Verify end-to-end functionality:

```bash
# Test webhook endpoint
curl -X POST http://localhost:5678/webhook-test/ad-script-refactor-openrouter \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: your-webhook-secret" \
  -d '{
    "task_id": "test-version-control",
    "reference_script": "function test() { var x = 1; return x; }",
    "outcome_description": "Modernize syntax"
  }'
```

### 3. Laravel Integration Testing

Test full Laravel integration:

```bash
# Submit via Laravel API
curl -X POST http://localhost:8000/api/ad-scripts \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "reference_script": "function test() { var x = 1; return x; }",
    "outcome_description": "Modernize syntax"
  }'
```

## Documentation Updates

### When to Update Documentation

Update documentation when:
- Workflow structure changes
- New nodes are added or removed
- Configuration requirements change
- Error handling is modified
- API endpoints change

### Documentation Files to Update

1. **WORKFLOW_DOCUMENTATION.md**: Main workflow documentation
2. **README.md**: Setup and usage instructions
3. **ERROR_HANDLING_GUIDE.md**: If error handling changes
4. **AI_AGENT_CONFIGURATION.md**: If AI configuration changes

### Documentation Checklist

- [ ] Update workflow version number
- [ ] Document new or changed nodes
- [ ] Update configuration requirements
- [ ] Revise setup instructions if needed
- [ ] Update troubleshooting guides
- [ ] Add to change log
- [ ] Update related documentation links

## Deployment Checklist

### Pre-Deployment

- [ ] Export workflow from development n8n
- [ ] Update workflow file in repository
- [ ] Update documentation
- [ ] Run automated tests
- [ ] Verify configuration requirements
- [ ] Create backup of current production workflow

### Deployment

- [ ] Import workflow to production n8n
- [ ] Configure credentials
- [ ] Set environment variables
- [ ] Test webhook endpoint
- [ ] Verify AI processing
- [ ] Test error handling scenarios
- [ ] Activate workflow
- [ ] Monitor initial executions

### Post-Deployment

- [ ] Verify end-to-end functionality
- [ ] Monitor error rates
- [ ] Check callback success rates
- [ ] Review execution logs
- [ ] Update monitoring dashboards
- [ ] Document any issues encountered

## Best Practices

### 1. Change Management

- Always export workflow after making changes
- Test changes in development environment first
- Document all changes in commit messages
- Update version numbers appropriately
- Maintain backward compatibility when possible

### 2. Security

- Never commit credentials to version control
- Use environment variables for sensitive data
- Regularly rotate API keys and secrets
- Review workflow permissions and access

### 3. Monitoring

- Monitor workflow execution success rates
- Track error patterns and frequencies
- Review performance metrics regularly
- Set up alerts for critical failures

### 4. Collaboration

- Use descriptive commit messages
- Document breaking changes clearly
- Coordinate workflow changes with team
- Maintain clear communication about deployments

This version control strategy ensures reliable, traceable, and maintainable n8n workflow management for the Laravel Ad Script Refactor system. 