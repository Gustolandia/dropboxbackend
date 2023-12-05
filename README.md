# Dropbox Clone Backend

 

## Introduction

This document provides setup instructions for the backend of a Dropbox clone. The backend runs on Ubuntu, using a ZFS-based storage pool. This was created for educational purposes within the internship at Datto in the summer of 2023.

 

### Prerequisites

- Ubuntu Server

- Drives for ZFS pool (virtualized in VM is acceptable)

 

## Setup Instructions

 

### ZFS Pool Creation

Create a ZFS pool named `filebox` using the following command:

```bash

sudo zpool create filebox -o ashift=12 raidz3 <drive_list>

 

Replace `<drive_list>` with your specific drives.

 

### Sudoers Configuration

Edit your sudoers file with `sudo visudo` and add the following lines for user `datto` to execute specific commands without a password:

```bash

@includedir /etc/sudoers.d

 

datto ALL=(ALL) NOPASSWD: /usr/sbin/zfs create filebox/*

datto ALL=(ALL) NOPASSWD: /usr/sbin/zfs clone filebox/*

datto ALL=(ALL) NOPASSWD: /usr/sbin/zfs rollback filebox/*

datto ALL=(ALL) NOPASSWD: /usr/sbin/zfs rollback -r filebox/*

datto ALL=(ALL) NOPASSWD: /usr/sbin/zfs destroy filebox/*

datto ALL=(ALL) NOPASSWD: /usr/sbin/zfs snapshot filebox/*

datto ALL=(ALL) NOPASSWD: /usr/local/bin/custom_chown.sh /filebox/*

datto ALL=(ALL) NOPASSWD: /usr/sbin/zfs destroy -r filebox/*

 

### Environment Configuration

Create a `.env` file in the project root with the following configuration:

 

```dotenv

# Symfony Framework Configuration

APP_ENV=dev

APP_SECRET=d636742a1444a59ecd046ded4bec491b

 

# Database Configuration

DATABASE_URL="mysql://root:test123@127.0.0.1:3306/api"

 

# JWT Authentication

JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem

JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem

JWT_PASSPHRASE=test123

 

# CORS Configuration

CORS_ALLOW_ORIGIN='^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$'

 

# File System Configuration

ROOT_DIRECTORY=/filebox

ROOT_ZPOOL=filebox

APP_DEBUG=1

 

### API Endpoints

 To understand how to integrate them with frontend end please check [Dropbox Froentend](https://github.com/Gustolandia/dropboxfrontend)

#### Authentication

Note: The Register and Login endpoints do not require a Bearer JWT for authentication.


- **Register User**: `POST /api/user/register`

- **Login**: `POST /api/user/login_check`

- **Logout**: `POST /api/user/logout`

 

#### User API

- **Get User Details**: `GET /api/user/me`

- **Edit User**: `PUT /api/user/edit`

- **Delete User**: `DELETE /api/user/delete`

 

#### File Service

- **Create File/Folder**: `POST /api/file/create/{type}`

- **Download File/Folder**: `GET /api/file/download/{type}/{id}`

- **Update File/Folder**: `PUT /api/file/update/{type}/{id}`

- **Delete File/Folder**: `DELETE /api/file/delete/{type}/{fileId}`

- **Get Metadata**: `GET /api/file/getMetadata/{parentId}`

- **List Suitable Folders**: `GET /api/file/suitable-folders/{type}/{id}`

 

#### ZFS Controller

- **Create Snapshot**: `POST /api/zfs/snapshot`

- **List Snapshots**: `GET /api/zfs/snapshots`

- **Delete Snapshot**: `DELETE /api/zfs/snapshot`

- **Recover system from Snapshot**: `POST /api/zfs/recovery`

 

## Usage Notes

- Follow the instructions carefully, especially when configuring ZFS and the `.env` file.

- The `.env` file settings provided are for development. Adjust for your production environment.

 

## Contributing

Feedback and contributions are welcome. Please adhere to the standard git workflow for contributions.

 

## License

Free for usage as long as credit is given. 
