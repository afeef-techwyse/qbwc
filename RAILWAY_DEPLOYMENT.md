# Railway Deployment Guide for QBWC PHP Application

## Overview
This PHP SOAP application is designed to work with QuickBooks Web Connector and is deployed on Railway. The application creates customers and invoices in QuickBooks.

## Railway-Specific Considerations

### 1. File System Persistence
Railway containers are ephemeral, which means:
- Files written to `/tmp` may not persist between deployments
- State files are used for session management between QBWC requests
- The application now tries multiple paths for better compatibility

### 2. Logging
The application includes Railway-specific logging:
- Logs to files when possible
- Falls back to system error log if file logging fails
- Outputs debug information to HTML comments for Railway logs

### 3. Environment Variables
Railway provides these environment variables that may be useful:
- `RAILWAY_ENVIRONMENT` - Current environment (production, preview, etc.)
- `PORT` - Port number for the application
- `RAILWAY_PROJECT_NAME` - Name of the Railway project

## Application Flow

### 1. Customer Query
- Checks if customer exists in QuickBooks
- Generates `CustomerQueryRq` XML

### 2. Customer Creation (if needed)
- Creates customer if not found
- Generates `CustomerAddRq` XML with full customer details

### 3. Item Validation
- Checks if items exist in QuickBooks before creating invoices
- Generates `ItemQueryRq` XML for each item

### 4. Invoice Creation
- Creates invoices with proper pricing and transaction dates
- Generates `InvoiceAddRq` XML

## Debugging on Railway

### 1. Check Railway Logs
```bash
railway logs
```

### 2. Look for Debug Information
The application outputs debug information as HTML comments:
```html
<!-- AddCustomerInvoiceApp: [timestamp] message -->
```

### 3. State File Locations
The application tries these paths in order:
1. `/tmp/qbwc_app_state.json`
2. `sys_get_temp_dir()/qbwc_app_state.json`
3. `src/QBWCServer/log/qbwc_app_state.json`

### 4. Debug Log Locations
The application tries these paths in order:
1. `/tmp/qbwc_app_debug.log`
2. `sys_get_temp_dir()/qbwc_app_debug.log`
3. `src/QBWCServer/log/qbwc_app_debug.log`

## QuickBooks Web Connector Setup

### 1. Application URL
Use your Railway app URL:
```
https://your-app-name.up.railway.app/examples/addCustomerInvoice.php
```

### 2. Authentication
- Username: `Admin`
- Password: `password` (as configured in the application)

### 3. Test Data
The application includes test orders for:
- John Doe (Order S1001) - Products A and B
- Jane Smith (Order S1002) - Service X

## Troubleshooting

### 1. Empty XML Response
- Check Railway logs for error messages
- Verify state file paths are writable
- Look for PHP errors in the logs

### 2. Customer Creation Issues
- Ensure customer names are unique
- Check that required fields are provided
- Verify QuickBooks company file is accessible

### 3. Invoice Creation Issues
- Ensure items exist in QuickBooks before creating invoices
- Check that pricing information is provided
- Verify customer exists before creating invoices

### 4. State Management Issues
- Check if state files are being created
- Verify file permissions on Railway
- Look for state file path errors in logs

## Monitoring

### 1. Railway Metrics
- Monitor CPU and memory usage
- Check for any container restarts
- Monitor response times

### 2. Application Logs
- Check for successful XML generation
- Monitor state transitions
- Look for error messages

### 3. QuickBooks Integration
- Verify QBWC connection status
- Check for authentication issues
- Monitor data synchronization

## Best Practices

### 1. Error Handling
- The application includes comprehensive error handling
- All errors are logged for debugging
- Graceful fallbacks for file operations

### 2. State Management
- State is persisted between requests
- Automatic cleanup after completion
- Multiple fallback paths for compatibility

### 3. Logging
- Detailed logging for debugging
- Multiple output methods for Railway compatibility
- Timestamped log entries

## Support

If you encounter issues:
1. Check Railway logs first
2. Look for debug information in HTML comments
3. Verify QuickBooks Web Connector configuration
4. Check that required items exist in QuickBooks
