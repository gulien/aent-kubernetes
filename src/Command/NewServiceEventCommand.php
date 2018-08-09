<?php

namespace TheAentMachine\AentKubernetes\Command;

use TheAentMachine\Aenthill\CommonEvents;
use TheAentMachine\Aenthill\CommonMetadata;
use TheAentMachine\Aenthill\Manifest;
use TheAentMachine\AentKubernetes\Kubernetes\K8sUtils;
use TheAentMachine\AentKubernetes\Kubernetes\KubernetesServiceDirectory;
use TheAentMachine\AentKubernetes\Kubernetes\Object\K8sConfigMap;
use TheAentMachine\AentKubernetes\Kubernetes\Object\K8sDeployment;
use TheAentMachine\AentKubernetes\Kubernetes\Object\K8sIngress;
use TheAentMachine\AentKubernetes\Kubernetes\Object\K8sPersistentVolumeClaim;
use TheAentMachine\AentKubernetes\Kubernetes\Object\K8sSecret;
use TheAentMachine\AentKubernetes\Kubernetes\Object\K8sService;
use TheAentMachine\Command\AbstractJsonEventCommand;
use TheAentMachine\Question\CommonValidators;
use TheAentMachine\Service\Enum\VolumeTypeEnum;
use TheAentMachine\Service\Environment\SharedEnvVariable;
use TheAentMachine\Service\Service;
use TheAentMachine\Service\Volume\NamedVolume;
use TheAentMachine\Service\Volume\Volume;
use TheAentMachine\YamlTools\YamlTools;

class NewServiceEventCommand extends AbstractJsonEventCommand
{

    protected function getEventName(): string
    {
        return CommonEvents::NEW_SERVICE_EVENT;
    }

    /**
     * @param array $payload
     * @return array|null
     * @throws \TheAentMachine\Exception\ManifestException
     * @throws \TheAentMachine\Exception\MissingEnvironmentVariableException
     * @throws \TheAentMachine\Service\Exception\ServiceException
     */
    protected function executeJsonEvent(array $payload): ?array
    {
        $service = Service::parsePayload($payload);
        if (!$service->isForMyEnvType()) {
            return null;
        }

        $k8sDirName = Manifest::mustGetMetadata(CommonMetadata::KUBERNETES_DIRNAME_KEY);
        $this->getAentHelper()->title('Kubernetes: adding/updating a service');

        $serviceName = $service->getServiceName();
        $k8sServiceDir = new KubernetesServiceDirectory($serviceName);

        if ($k8sServiceDir->exist()) {
            $this->output->writeln('☸️ <info>' . $k8sServiceDir->getDirName(true) . '</info> found!');
        } else {
            $k8sServiceDir->findOrCreate();
            $this->output->writeln('☸️ <info>' . $k8sServiceDir->getDirName(true) . '</info> was successfully created!');
        }
        $this->getAentHelper()->spacer();


        // Deployment
        if (null === $service->getRequestMemory()) {
            $requestMemory = $this->getAentHelper()->question("Memory request for <info>$serviceName</info>")
                ->compulsory()
                ->setHelpText('Amount of guaranteed memory (in bytes). A Container can exceed its memory request if the Node has memory available.')
                ->setValidator(K8sUtils::getMemoryValidator())
                ->ask();
            $service->setRequestMemory($requestMemory);
        }
        if (null === $service->getRequestCpu()) {
            $requestCpu = $this->getAentHelper()->question("CPU request for <info>$serviceName</info>")
                ->compulsory()
                ->setHelpText('Amount of guaranteed cpu units (fractional values are allowed e.g. 0.1 cpu). a Container can exceed its cpu request if the Node has available cpus.')
                ->setValidator(K8sUtils::getCpuValidator())
                ->ask();
            $service->setRequestCpu($requestCpu);
        }
        if (null === $service->getLimitMemory()) {
            $limitMemory = $this->getAentHelper()->question("Memory limit for <info>$serviceName</info>")
                ->compulsory()
                ->setHelpText('Amount of memory (in bytes) that a Container is not allowed to exceed. If a Container allocates more memory than its limit, the Container becomes a candidate for termination.')
                ->setValidator(K8sUtils::getMemoryValidator())
                ->ask();
            $service->setLimitMemory($limitMemory);
        }
        if (null === $service->getLimitCpu()) {
            $limitCpu = $this->getAentHelper()->question("CPU limit for <info>$serviceName</info>")
                ->compulsory()
                ->setHelpText('Max cpu units (fractional values are allowed e.g. 0.1 cpu) that a Container is allowed to use. The limit is guaranteed by throttling.')
                ->setValidator(K8sUtils::getCpuValidator())
                ->ask();
            $service->setLimitCpu($limitCpu);
        }
        $deploymentArray = K8sDeployment::serializeFromService($service, $serviceName);
        $deploymentFilename = $k8sServiceDir->getPath() . '/deployment.yml';
        YamlTools::mergeContentIntoFile($deploymentArray, $deploymentFilename);

        // Service
        $useNodePortForIngress = (bool) Manifest::mustGetMetadata('USE_NODEPORT_FOR_INGRESS');
        $serviceArray = K8sService::serializeFromService($service, $useNodePortForIngress);
        $filePath = $k8sServiceDir->getPath() . '/service.yml';
        YamlTools::mergeContentIntoFile($serviceArray, $filePath);

        // Secret
        $allSharedSecrets = $service->getAllSharedSecret();
        if (!empty($allSharedSecrets)) {
            $sharedSecretsMap = K8sUtils::mapSharedEnvVarsByContainerId($allSharedSecrets);
            foreach ($sharedSecretsMap as $containerId => $sharedSecrets) {
                $secretObjName = K8sUtils::getSecretName($containerId);
                $tmpService = new Service();
                $tmpService->setServiceName($serviceName);
                /** @var SharedEnvVariable $secret */
                foreach ($sharedSecrets as $key => $secret) {
                    $tmpService->addSharedSecret($key, $secret->getValue(), $secret->getComment(), $secret->getContainerId());
                }
                $secretArray = K8sSecret::serializeFromService($tmpService, $secretObjName);
                $filePath = \dirname($k8sServiceDir->getPath()) . '/' . $secretObjName . '.yml';
                YamlTools::mergeContentIntoFile($secretArray, $filePath);
            }
        }

        // ConfigMap
        $allSharedEnvVars = $service->getAllSharedEnvVariable();
        if (!empty($allSharedEnvVars)) {
            $sharedSecretsMap = K8sUtils::mapSharedEnvVarsByContainerId($allSharedEnvVars);
            foreach ($sharedSecretsMap as $containerId => $sharedEnvVars) {
                $configMapName = K8sUtils::getConfigMapName($containerId);
                $tmpService = new Service();
                $tmpService->setServiceName($serviceName);
                /** @var SharedEnvVariable $sharedEnvVar */
                foreach ($sharedEnvVars as $key => $sharedEnvVar) {
                    $tmpService->addSharedEnvVariable($key, $sharedEnvVar->getValue(), $sharedEnvVar->getComment(), $sharedEnvVar->getContainerId());
                }
                $secretArray = K8sConfigMap::serializeFromService($service, $configMapName);
                $filePath = \dirname($k8sServiceDir->getPath()) . '/' . $configMapName . '.yml';
                YamlTools::mergeContentIntoFile($secretArray, $filePath);
            }
        }

        // Ingress
        if (!empty($virtualHosts = $service->getVirtualHosts())) {
            $baseDomainName = Manifest::mustGetMetadata('BASE_DOMAIN_NAME');

            $ingressFilename = $k8sServiceDir->getPath() . '/ingress.yml';
            $tmpService = new Service();
            $tmpService->setServiceName($serviceName);
            foreach ($virtualHosts as $virtualHost) {
                $port = (int)$virtualHost['port'];
                $host = $virtualHost['host'] ?? null;
                $hostPrefix = $virtualHost['hostPrefix'] ?? null;
                if ($hostPrefix !== null) {
                    $host = $hostPrefix . $baseDomainName;
                }
                if (null === $host) {
                    $host = $this->getAentHelper()->question("What is the domain name of your service <info>$serviceName</info> (port <info>$port</info>)? ")
                        ->compulsory()
                        ->setDefault($serviceName . $baseDomainName)
                        ->setValidator(CommonValidators::getDomainNameValidator())
                        ->ask();
                }
                $comment = $virtualHost['comment'] ?? null;
                if ($comment !== null) {
                    $comment = (string)$comment;
                }
                $tmpService->addVirtualHost((string)$host, $port, $comment);
            }

            $ingressClass = Manifest::mustGetMetadata('INGRESS_CLASS');
            $useCertManager = (bool) Manifest::mustGetMetadata('CERT_MANAGER');

            YamlTools::mergeContentIntoFile(K8sIngress::serializeFromService($tmpService, $ingressClass, $useCertManager), $ingressFilename);
        }

        // PVC
        $namedVolumes = array_filter($service->getVolumes(), function (Volume $v) {
            return $v->getType() === VolumeTypeEnum::NAMED_VOLUME;
        });
        if ($namedVolumes) {
            /** @var NamedVolume $v */
            foreach ($namedVolumes as $v) {
                $requestStorage = $v->getRequestStorage();
                if (null === $requestStorage) {
                    $requestStorage = $this->getAentHelper()->question("Storage request for <info>$serviceName</info>")
                        ->compulsory()
                        ->setHelpText('Amount of guaranteed storage in bytes (e.g. 8G, 0.5Ti).')
                        ->setValidator(K8sUtils::getStorageValidator())
                        ->ask();
                    $v = new NamedVolume($v->getSource(), $v->getTarget(), $v->isReadOnly(), $v->getComment(), $requestStorage);
                }
                $pvcArray = K8sPersistentVolumeClaim::serializeFromNamedVolume($v);
                $filePath = $k8sServiceDir->getPath() . '/' . K8sUtils::getPvcName($v->getSource()) . '.yml';
                YamlTools::mergeContentIntoFile($pvcArray, $filePath);
            }
        }

        $this->output->writeln("Service <info>$serviceName</info> has been successfully added in <info>$k8sDirName</info>!");
        return null;
    }
}
