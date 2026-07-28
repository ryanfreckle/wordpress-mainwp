const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

// Default to restoring latest.sql
const backupFile = process.argv[2] || path.join(process.cwd(), 'backups', 'latest.sql');

try {
  // Check if backup file exists
  if (!fs.existsSync(backupFile)) {
    console.error(`Backup file not found: ${backupFile}`);
    process.exit(1);
  }

  // List all containers to find the right ones
  const containers = execSync('docker ps --format "{{.Names}} {{.Image}}"').toString().trim().split(/\r?\n/);
  console.log('Available containers:');
  containers.forEach(container => console.log(`- ${container}`));

  // First try the database container with mariadb
  const dbContainer = containers.find(container => container.includes('db-1')).split(' ')[0];

  console.log(`Trying MariaDB container: ${dbContainer}...`);
  console.log(`Restoring from: ${backupFile}`);

  try {
    // Try using mariadb directly
    execSync(
      `type "${backupFile}" | docker exec -i ${dbContainer} mariadb -u root -phighwind wordpress`,
      { stdio: 'inherit' }
    );

    console.log('Database restored successfully using mariadb client');
    process.exit(0);
  } catch (dbError) {
    console.log(`MariaDB restore failed: ${dbError.message}`);
    console.log('Falling back to WordPress WP-CLI method...');
  }

  // Find the WP container (Apache or WordPress)
  const wpContainer = containers.find(container =>
    container.toLowerCase().includes('apache') ||
    container.toLowerCase().includes('wordpress')
  );

  if (!wpContainer) {
    console.error('WordPress container not found. Make sure Docker is running with the WordPress container.');
    process.exit(1);
  }

  const container = wpContainer.split(' ')[0];
  console.log(`Using WordPress container: ${container}`);
  console.log('Running database restore using WP-CLI...');

  // Create a temporary file inside the container
  const tempFile = '/tmp/wp-restore.sql';

  // Copy the backup file to the container
  execSync(
    `docker cp "${backupFile}" ${container}:${tempFile}`,
    { stdio: 'inherit' }
  );

  // Use WP-CLI to import the database
  execSync(
    `docker exec ${container} wp db import ${tempFile} --allow-root`,
    { stdio: 'inherit' }
  );

  // Clean up the temporary file in the container
  execSync(
    `docker exec ${container} rm ${tempFile}`,
    { stdio: 'inherit' }
  );

  console.log('Database restored successfully using WP-CLI');
} catch (error) {
  console.error(`Restore failed: ${error.message}`);

  // Try using wp-cli container if available
  try {
    console.log('Attempting restore via wp-cli container...');
    const wpcliContainer = containers.find(container => container.includes('wp-cli')).split(' ')[0];

    console.log(`Using WP-CLI container: ${wpcliContainer}`);

    // Create a temporary file inside the container
    const tempFile = '/tmp/wp-restore.sql';

    // Copy the backup file to the container
    execSync(
      `docker cp "${backupFile}" ${wpcliContainer}:${tempFile}`,
      { stdio: 'inherit' }
    );

    // Use WP-CLI to import the database
    execSync(
      `docker exec ${wpcliContainer} wp db import ${tempFile} --allow-root`,
      { stdio: 'inherit' }
    );

    // Clean up the temporary file in the container
    execSync(
      `docker exec ${wpcliContainer} rm ${tempFile}`,
      { stdio: 'inherit' }
    );

    console.log('Database restored successfully via WP-CLI container');
  } catch (cliError) {
    console.error('All restore methods failed');
    console.error('Error details:');
    console.error(error.message);
    console.error(cliError ? cliError.message : 'No WP-CLI container available');
    process.exit(1);
  }
}
