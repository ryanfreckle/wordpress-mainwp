const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

// Create backups directory if it doesn't exist
const backupsDir = path.join(process.cwd(), 'backups');
if (!fs.existsSync(backupsDir)) {
  fs.mkdirSync(backupsDir, { recursive: true });
}

// Format date for filename
const now = new Date();
const timestamp = now.toISOString()
  .replace(/[-T:]/g, '')
  .replace(/\..+/, '')
  .replace(/(\d{8})(\d{6})/, '$1-$2');

const filename = path.join(backupsDir, `db-${timestamp}.sql`);
const latestFilename = path.join(backupsDir, 'latest.sql');

try {
  // List all containers to find the right one
  const containers = execSync('docker ps --format "{{.Names}} {{.Image}}"').toString().trim().split(/\r?\n/);
  console.log('Available containers:');
  containers.forEach(container => console.log(`- ${container}`));

  // First try the database container with mariadb-dump
  const dbContainer = containers.find(container => container.includes('db-1')).split(' ')[0];

  console.log(`Trying MariaDB container: ${dbContainer} with mariadb-dump...`);

  try {
    // Try using mariadb-dump
    execSync(
      `docker exec ${dbContainer} mariadb-dump -u root -phighwind wordpress > "${filename}"`,
      { stdio: 'inherit' }
    );

    // Create a copy as latest.sql
    fs.copyFileSync(filename, latestFilename);

    console.log(`Database backed up to ${filename} using mariadb-dump`);
    console.log(`Created latest.sql reference`);
    process.exit(0);
  } catch (dbError) {
    console.log(`MariaDB dump failed: ${dbError.message}`);
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
  console.log('Running database backup using WP-CLI...');

  // Create a temporary file inside the container
  const tempFile = '/tmp/wp-backup.sql';

  // Use WP-CLI to export the database
  execSync(
    `docker exec ${container} wp db export ${tempFile} --allow-root`,
    { stdio: 'inherit' }
  );

  // Copy the file from container to host
  execSync(
    `docker cp ${container}:${tempFile} "${filename}"`,
    { stdio: 'inherit' }
  );

  // Clean up the temporary file in the container
  execSync(
    `docker exec ${container} rm ${tempFile}`,
    { stdio: 'inherit' }
  );

  // Create a copy as latest.sql
  fs.copyFileSync(filename, latestFilename);

  console.log(`Database backed up to ${filename}`);
  console.log(`Created latest.sql reference`);
} catch (error) {
  console.error(`Backup failed: ${error.message}`);

  // Try using wp-cli container if available
  try {
    console.log('Attempting backup via wp-cli container...');
    const wpcliContainer = containers.find(container => container.includes('wp-cli')).split(' ')[0];

    console.log(`Using WP-CLI container: ${wpcliContainer}`);

    // Create a temporary file inside the container
    const tempFile = '/tmp/wp-backup.sql';

    // Use WP-CLI to export the database
    execSync(
      `docker exec ${wpcliContainer} wp db export ${tempFile} --allow-root`,
      { stdio: 'inherit' }
    );

    // Copy the file from container to host
    execSync(
      `docker cp ${wpcliContainer}:${tempFile} "${filename}"`,
      { stdio: 'inherit' }
    );

    // Clean up the temporary file in the container
    execSync(
      `docker exec ${wpcliContainer} rm ${tempFile}`,
      { stdio: 'inherit' }
    );

    // Create a copy as latest.sql
    fs.copyFileSync(filename, latestFilename);

    console.log(`Database backed up to ${filename} via WP-CLI container`);
    console.log(`Created latest.sql reference`);
  } catch (cliError) {
    console.error('All backup methods failed');
    console.error('Error details:');
    console.error(error.message);
    console.error(cliError.message);
    process.exit(1);
  }
}
