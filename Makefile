.PHONY: build release

# Commit it, and tag the release. Commit code changes first.
# Usage: make tag VERSION=1.0.0
tag:
ifndef VERSION
	$(error VERSION is required, e.g. make release VERSION=1.0.0)
endif
	git tag $(VERSION)
	git push
	git push origin $(VERSION)
	@echo ""
	@echo "Tagged and pushed $(VERSION)."
