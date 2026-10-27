# Developer Training Platform

Laravel application for running the L4 Accelerator Training program: curriculum, sessions, participant submissions, coach reviews and Google Drive video sharing.

- Deployment plan and release procedure: [docs/AWS_DEPLOYMENT.md](docs/AWS_DEPLOYMENT.md)
- Code quality plan and conventions: [docs/CODE_QUALITY_PLAN.md](docs/CODE_QUALITY_PLAN.md)

## Production environment (AWS)

State as of 27 Oct 2026: the server and database exist and are connected; the application is not deployed yet.

Passwords are never stored in this repository. The `admin` and `dev_training_app` database passwords and the `training-web.pem` key are kept in the team's password manager.

### Server (EC2)

| Item | Value |
|---|---|
| Region | US East (Ohio), `us-east-2` |
| Instance ID | `i-062140707fc5036d8` |
| Operating system | Amazon Linux (login user `ec2-user`) |
| Public IP | `3.14.133.1` (temporary, see open items) |
| Public DNS | `ec2-3-14-133-1.us-east-2.compute.amazonaws.com` |
| Private IP | `172.31.0.121` |
| VPC | Default VPC (`172.31.0.0/16`) |
| Key pair | `training-web` (ED25519) |
| Security group | `training-web` (`sg-04b35939609325d3c`) |
| IAM role | `AmazonEC2RoleforSSM` (temporary, see open items) |

`training-web` inbound rules:

| Port | Source | Purpose |
|---|---|---|
| 22 | Administrators' IPs (`/32`) | SSH |
| 80 | `0.0.0.0/0` | HTTP (redirects to HTTPS) |
| 443 | `0.0.0.0/0` | HTTPS |

### Database (RDS MySQL)

| Item | Value |
|---|---|
| Identifier | `training-web-db` |
| Endpoint | `training-web-db.chs5dvwmkvnl.us-east-2.rds.amazonaws.com` |
| Port | `3306` |
| Instance class | `db.t4g.micro` |
| Public access | No (reachable only from inside the VPC) |
| Security group | `training-db`: inbound 3306 from `training-web` only |
| Certificate authority | `rds-ca-rsa2048-g1` |
| Database | `dev_training_platform` (`utf8mb4`, `utf8mb4_unicode_ci`) |
| Master user | `admin`: administration only, self-managed password |
| Application user | `dev_training_app`: all privileges on `dev_training_platform` only |

Backup retention and deletion protection are enabled.

## Connecting

### SSH

Add to `~/.ssh/config`, with the key at `~/.ssh/training-web.pem` (mode `600`):

```
Host training
    HostName 3.14.133.1
    User ec2-user
    IdentityFile ~/.ssh/training-web.pem
    IdentitiesOnly yes

Host training-db
    HostName 3.14.133.1
    User ec2-user
    IdentityFile ~/.ssh/training-web.pem
    IdentitiesOnly yes
    LocalForward 3307 training-web-db.chs5dvwmkvnl.us-east-2.rds.amazonaws.com:3306
```

Then `ssh training`. If the connection times out, your public IP has probably changed: in the `training-web` security group, edit the SSH rule and set its source to *My IP*.

### MySQL from the server

```bash
ssh training
mysql -h training-web-db.chs5dvwmkvnl.us-east-2.rds.amazonaws.com -P 3306 -u admin -p \
  --ssl --ssl-verify-server-cert --ssl-ca=$HOME/global-bundle.pem
```

The client is MariaDB's (`sudo dnf install -y mariadb105`). The CA bundle comes from `https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem`.

### MySQL Workbench (through an SSH tunnel)

1. Start the tunnel and leave it running: `ssh -N training-db`.
2. Create a Workbench connection:

   | Field | Value |
   |---|---|
   | Connection method | Standard (TCP/IP) |
   | Hostname | `127.0.0.1` |
   | Port | `3307` |
   | Username | `admin` or `dev_training_app` |
   | SSL mode | Require and Verify CA |
   | Certificate Authority file | `~/.ssh/rds-global-bundle.pem` (full path) |

3. Stop the tunnel with Ctrl+C when finished.

Use *Require and Verify CA*, not *Verify Identity*: through a tunnel the client connects to `127.0.0.1`, which never matches the certificate's hostname.

### Production `.env` database settings

```dotenv
DB_CONNECTION=mysql
DB_HOST=training-web-db.chs5dvwmkvnl.us-east-2.rds.amazonaws.com
DB_PORT=3306
DB_DATABASE=dev_training_platform
DB_USERNAME=dev_training_app
DB_PASSWORD=
MYSQL_ATTR_SSL_CA=/var/www/training/shared/global-bundle.pem
```

`DB_CONNECTION` must be set explicitly: `config/database.php` falls back to SQLite without it.

## Open items

- **Elastic IP:** the public IP changes on every stop and start. An AWS administrator must allocate an Elastic IP and associate it before the DNS record is created (`fs-training` is not allowed `ec2:AllocateAddress`); then update the SSH config above.
- **Domain:** the hostname is not decided yet. It is needed for HTTPS, `APP_URL` and the Google OAuth redirect URI before the first session (9 Nov 2026).
- **IAM role:** `AmazonEC2RoleforSSM` grants read and write on every S3 bucket in the account. An AWS administrator should replace it with a `training-ec2` role (`AmazonSSMManagedInstanceCore`, `CloudWatchAgentServerPolicy`). The `fs-training` user cannot change roles or start Session Manager sessions.
- **Provisioning steps:** [docs/AWS_DEPLOYMENT.md](docs/AWS_DEPLOYMENT.md) still describes Ubuntu (`apt`, `www-data`, `/etc/php/8.4/...`). The server runs Amazon Linux, so those steps need translating before PHP and nginx are installed.
