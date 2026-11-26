# Jenkins Integration

EasyAudit integrates with Jenkins for automated code scanning. Results can be archived as build artifacts.

---

## Quick Start

Create a `Jenkinsfile` in your repository root:

```groovy
pipeline {
    agent {
        docker {
            image 'ghcr.io/crealoz/easyaudit:latest'
        }
    }

    stages {
        stage('EasyAudit Scan') {
            steps {
                sh '''
                    mkdir -p report
                    easyaudit scan \
                        --format=sarif \
                        --output=report/easyaudit.sarif \
                        --exclude="vendor,generated,var,pub/static,pub/media" \
                        "$WORKSPACE"
                '''
            }
        }
    }

    post {
        always {
            archiveArtifacts artifacts: 'report/*', fingerprint: true
        }
    }
}
```

---

## Workflow Variants

### Scan on Pull Requests Only

```groovy
pipeline {
    agent {
        docker {
            image 'ghcr.io/crealoz/easyaudit:latest'
        }
    }

    triggers {
        pullRequest()
    }

    stages {
        stage('EasyAudit Scan') {
            steps {
                sh '''
                    mkdir -p report
                    easyaudit scan \
                        --format=sarif \
                        --output=report/easyaudit.sarif \
                        "$WORKSPACE/app/code"
                '''
            }
        }
    }

    post {
        always {
            archiveArtifacts artifacts: 'report/*', fingerprint: true
        }
    }
}
```

### Fail on Errors

```groovy
pipeline {
    agent {
        docker {
            image 'ghcr.io/crealoz/easyaudit:latest'
        }
    }

    stages {
        stage('EasyAudit Scan') {
            steps {
                sh 'mkdir -p report'
                script {
                    def exitCode = sh(
                        script: '''
                            easyaudit scan \
                                --format=sarif \
                                --output=report/easyaudit.sarif \
                                --exclude="vendor,generated,var" \
                                "$WORKSPACE"
                        ''',
                        returnStatus: true
                    )
                    if (exitCode == 2) {
                        error('EasyAudit found critical issues')
                    }
                }
            }
        }
    }

    post {
        always {
            archiveArtifacts artifacts: 'report/*', fingerprint: true
        }
    }
}
```

### Scheduled Weekly Scan

```groovy
pipeline {
    agent {
        docker {
            image 'ghcr.io/crealoz/easyaudit:latest'
        }
    }

    triggers {
        cron('H 6 * * 1')  // Every Monday around 6am
    }

    stages {
        stage('Full EasyAudit Scan') {
            steps {
                sh '''
                    mkdir -p report
                    easyaudit scan \
                        --format=sarif \
                        --output=report/easyaudit.sarif \
                        --exclude="vendor,generated,var,pub/static,pub/media,dev,setup" \
                        "$WORKSPACE"
                '''
            }
        }
    }

    post {
        always {
            archiveArtifacts artifacts: 'report/*', fingerprint: true
        }
    }
}
```

---

## Environment Variables

| Variable | Description |
|----------|-------------|
| `WORKSPACE` | Repository root (auto-set) |
| `JOB_NAME` | Job name |
| `BUILD_ID` | Unique build ID |

---

## Viewing Results

1. Go to your Jenkins job
2. Click on the completed build
3. Click **Build Artifacts** in the sidebar
4. Download `report/easyaudit.sarif`

---

## See Also

- [CLI Usage](../cli-usage.md) - Local usage
- [Processors](../processors.md) - Available checks

---

[Back to CI/CD Overview](../ci-cd.md) | [Back to README](../../README.md)
