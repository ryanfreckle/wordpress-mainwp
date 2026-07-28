const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

// Check if a file path was provided
if (process.argv.length < 3) {
  console.error('Please provide a SQL file to import: npm run db:import -- path/to/file.sql');
  process.exit(1);
}

const sqlFile = process.argv[2];

try {
  // Check if import file exists
  if (!fs.existsSync(sqlFile)) {
    console.error(`SQL file not found: ${sqlFile}`);
    process.exit(1);
  }

  // List all containers to find the right ones
  const containers = execSync('docker ps --format "{{.Names}} {{.Image}}"').toString().trim().split(/\r?\n/);
  console.log('Available containers:');
  containers.forEach(container => console.log(`- ${container}`));

  // First try the database container with mariadb
  const dbContainer = containers.find(container => container.includes('db-1')).split(' ')[0];

  console.log(`Trying MariaDB container: ${dbContainer}...`);
  console.log(`Importing from: ${sqlFile}`);

  try {
    // Try using mariadb directly
    const catCmd = process.platform === 'win32' ? 'type' : 'cat';
    execSync(
      `${catCmd} "${sqlFile}" | docker exec -i ${dbContainer} mariadb -u root -phighwind wordpress`,
      { stdio: 'inherit' }
    );

    console.log('Database imported successfully using mariadb client');
    process.exit(0);
  } catch (dbError) {
    console.log(`MariaDB import failed: ${dbError.message}`);
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
  console.log('Running database import using WP-CLI...');

  // Create a temporary file inside the container
  const tempFile = '/tmp/wp-import.sql';

  // Copy the SQL file to the container
  execSync(
    `docker cp "${sqlFile}" ${container}:${tempFile}`,
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

  console.log('Database imported successfully using WP-CLI');
} catch (error) {
  console.error(`Import failed: ${error.message}`);

  // Try using wp-cli container if available
  try {
    console.log('Attempting import via wp-cli container...');
    const wpcliContainer = containers.find(container => container.includes('wp-cli')).split(' ')[0];

    console.log(`Using WP-CLI container: ${wpcliContainer}`);

    // Create a temporary file inside the container
    const tempFile = '/tmp/wp-import.sql';

    // Copy the SQL file to the container
    execSync(
      `docker cp "${sqlFile}" ${wpcliContainer}:${tempFile}`,
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

    console.log('Database imported successfully via WP-CLI container');
  } catch (cliError) {
    console.error('All import methods failed');
    console.error('Error details:');
    console.error(error.message);
    console.error(cliError ? cliError.message : 'No WP-CLI container available');
    process.exit(1);
  }
}
