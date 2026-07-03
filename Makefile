# Release build for an XC_VM module repository.
# Produces the two assets the panel fetches per release tag:
#   module.tar.gz  — the module tree (module.json at the archive root)
#   hashes.md5     — "<md5>  module.tar.gz" (checked by GitHubReleases::getAssetHash)
MODULE_TAR := module.tar.gz
HASH_FILE  := hashes.md5

.PHONY: release clean

release: clean
	@tmp=$$(mktemp) && tar \
	  --exclude=./.git \
	  --exclude=./.github \
	  --exclude=./Makefile \
	  --exclude=./README.md \
	  --exclude=./LICENSE \
	  --exclude=./.gitignore \
	  -czf "$$tmp" -C . . && mv "$$tmp" $(MODULE_TAR)
	md5sum $(MODULE_TAR) > $(HASH_FILE)
	@echo "Built $(MODULE_TAR) + $(HASH_FILE)"

clean:
	@rm -f $(MODULE_TAR) $(HASH_FILE)
